<?php

namespace App\Controller;
use Artgris\Bundle\FileManagerBundle\Controller\ManagerController;
use Artgris\Bundle\FileManagerBundle\Event\FileManagerEvents;
use Artgris\Bundle\FileManagerBundle\Helpers\File;
use Artgris\Bundle\FileManagerBundle\Helpers\FileManager;
use Artgris\Bundle\FileManagerBundle\Helpers\UploadHandler;
use Artgris\Bundle\FileManagerBundle\Twig\OrderExtension;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Psr\Log\LoggerInterface;

class FileManagerController extends ManagerController
{
    /**
     * @Route("/profile/{username}/fms", name="file_management")
     * Open a page to access file management system.
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Exception
     */
    public function indexAction(Request $request)
    {
        $queryParameters = $request->query->all();
        $translator = $this->get('translator');
        $isJson = $request->get('json') ? true : false;
        if ($isJson) {
            unset($queryParameters['json']);
        }
        $fileManager = $this->newFileManager($queryParameters);

        // Folder search
        $directoriesArbo = $this->retrieveSubDirectories($fileManager, $fileManager->getDirName(), DIRECTORY_SEPARATOR, $fileManager->getBaseName());

        // File search
        $finderFiles = new Finder();
        $finderFiles->in($fileManager->getCurrentPath())->depth(0);
        $regex = $fileManager->getRegex();

        $orderBy = $fileManager->getQueryParameter('orderby');
        $orderDESC = OrderExtension::DESC === $fileManager->getQueryParameter('order');
        if (!$orderBy) {
            $finderFiles->sortByType();
        }

        switch ($orderBy) {
            case 'name':
                $finderFiles->sort(function (SplFileInfo $a, SplFileInfo $b) {
                    return strcmp(strtolower($b->getFilename()), strtolower($a->getFilename()));
                });
                break;
            case 'date':
                $finderFiles->sortByModifiedTime();
                break;
            case 'size':
                $finderFiles->sort(function (\SplFileInfo $a, \SplFileInfo $b) {
                    return $a->getSize() - $b->getSize();
                });
                break;
        }

        if ($fileManager->getTree()) {
            $finderFiles->files()->name($regex)->filter(function (SplFileInfo $file) {
                return $file->isReadable();
            });
        } else {
            $finderFiles->filter(function (SplFileInfo $file) use ($regex) {
                if ('file' === $file->getType()) {
                    if (preg_match($regex, $file->getFilename())) {
                        return $file->isReadable();
                    }

                    return false;
                }

                return $file->isReadable();
            });
        }

        $formDelete = $this->createDeleteForm()->createView();
        $fileArray = [];
        foreach ($finderFiles as $file) {
            $fileArray[] = new File($file, $this->get('translator'), $this->get('file_type_service'), $fileManager);
        }

        if ('dimension' === $orderBy) {
            usort($fileArray, function (File $a, File $b) {
                $aDimension = $a->getDimension();
                $bDimension = $b->getDimension();
                if ($aDimension && !$bDimension) {
                    return 1;
                }

                if (!$aDimension && $bDimension) {
                    return -1;
                }

                if (!$aDimension && !$bDimension) {
                    return 0;
                }

                return ($aDimension[0] * $aDimension[1]) - ($bDimension[0] * $bDimension[1]);
            });
        }

        if ($orderDESC) {
            $fileArray = array_reverse($fileArray);
        }

        $parameters = [
            'fileManager' => $fileManager,
            'fileArray' => $fileArray,
            'formDelete' => $formDelete,
        ];

        if ($isJson) {
            $fileList = $this->renderView('@ArtgrisFileManager/views/_manager_view.html.twig', $parameters);

            return new JsonResponse(['data' => $fileList, 'badge' => $finderFiles->count(), 'treeData' => $directoriesArbo]);
        }
        $parameters['treeData'] = json_encode($directoriesArbo);

        $form = $this->get('form.factory')->createNamedBuilder('rename', FormType::class)
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
                'data' => $translator->trans('input.default'),
            ])
            ->add('send', SubmitType::class, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => $translator->trans('button.save'),
            ])
            ->getForm();

        /* @var Form $form */
        $form->handleRequest($request);
        /** @var Form $formRename */
        $formRename = $this->createRenameForm();

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $fs = new Filesystem();
            $directory = $directorytmp = $fileManager->getCurrentPath().DIRECTORY_SEPARATOR.$data['name'];
            $i = 1;

            while ($fs->exists($directorytmp)) {
                $directorytmp = "{$directory} ({$i})";
                ++$i;
            }
            $directory = $directorytmp;

            try {
                $fs->mkdir($directory);
                $this->addFlash('success', $translator->trans('folder.add.success'));
            } catch (IOExceptionInterface $e) {
                $this->addFlash('danger', $translator->trans('folder.add.danger', ['%message%' => $data['name']]));
            }

            return $this->redirectToRoute('file_manager', $fileManager->getQueryParameters());
        }
        $parameters['form'] = $form->createView();
        $parameters['formRename'] = $formRename->createView();

        return $this->render('fileManager/fileManager.html.twig', $parameters);
    }

    /**
     * @param $queryParameters
     *
     * @return FileManager
     *
     * @throws \Exception
     */
    protected function newFileManager($queryParameters)
    {
        if (!isset($queryParameters['conf'])) {
            throw new \RuntimeException('Please define a conf parameter in your route');
        }
        $webDir = $this->getParameter('artgris_file_manager')['web_dir'];

        $this->fileManager = new FileManager($queryParameters, $this->getBasePath($queryParameters), $this->getKernelRoute(), $this->get('router'), $webDir);

        return $this->fileManager;
    }

    /*
     * Base Path
     */
    protected function getBasePath($queryParameters)
    {
        $conf = $queryParameters['conf'];
        $managerConf = $this->getParameter('artgris_file_manager')['conf'];
        if (isset($managerConf[$conf]['dir'])) {
            return $managerConf[$conf];
        }

        if (isset($managerConf[$conf]['service'])) {
            $extra = isset($queryParameters['extra']) ? $queryParameters['extra'] : [];
            $conf = $this->get($managerConf[$conf]['service'])->getConf($extra);

            return $conf;
        }

        throw new \RuntimeException('Please define a "dir" or a "service" parameter in your config.yml');
    }

    /**
     * @return mixed
     */
    protected function getKernelRoute()
    {
        return $this->getParameter('kernel.root_dir');
    }

    /**
     * @param FileManager $fileManager
     * @param $path
     * @param string $parent
     * @param bool   $baseFolderName
     *
     * @return array|null
     */
    protected function retrieveSubDirectories(FileManager $fileManager, $path, $parent = DIRECTORY_SEPARATOR, $baseFolderName = false)
    {
        $directories = new Finder();
        $directories->in($path)->ignoreUnreadableDirs()->directories()->depth(0)->sortByType()->filter(function (SplFileInfo $file) {
            return $file->isReadable();
        });

        if ($baseFolderName) {
            $directories->name($baseFolderName);
        }
        $directoriesList = null;

        foreach ($directories as $directory) {
            /** @var SplFileInfo $directory */
            $fileName = $baseFolderName ? '' : $parent.$directory->getFilename();

            $queryParameters = $fileManager->getQueryParameters();
            $queryParameters['route'] = $fileName;
            $queryParametersRoute = $queryParameters;
            unset($queryParametersRoute['route']);

            $filesNumber = $this->retrieveFilesNumber($directory->getPathname(), $fileManager->getRegex());
            $fileSpan = $filesNumber > 0 ? " <span class='label label-default'>{$filesNumber}</span>" : '';

            $directoriesList[] = [
                'text' => $directory->getFilename().$fileSpan,
                'icon' => 'far fa-folder-open',
                'children' => $this->retrieveSubDirectories($fileManager, $directory->getPathname(), $fileName.DIRECTORY_SEPARATOR),
                'a_attr' => [
                    'href' => $fileName ? $this->generateUrl('file_manager', $queryParameters) : $this->generateUrl('file_manager', $queryParametersRoute),
                ], 'state' => [
                    'selected' => $fileManager->getCurrentRoute() === $fileName,
                    'opened' => true,
                ],
            ];
        }

        return $directoriesList;
    }

    /**
     * Tree Iterator.
     *
     * @param $path
     * @param $regex
     *
     * @return int
     */
    protected function retrieveFilesNumber($path, $regex)
    {
        $files = new Finder();
        $files->in($path)->files()->depth(0)->name($regex);

        return iterator_count($files);
    }

    /**
     * @return Form|\Symfony\Component\Form\FormInterface
     */
    protected function createDeleteForm()
    {
        return $this->createFormBuilder()
            ->setMethod('DELETE')
            ->add('DELETE', SubmitType::class, [
                'translation_domain' => 'messages',
                'attr' => [
                    'class' => 'btn btn-danger',
                ],
                'label' => 'button.delete.action',
            ])
            ->getForm();
    }

    /**
     * @return mixed
     */
    protected function createRenameForm()
    {
        return $this->createFormBuilder()
            ->add('name', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
            ])->add('extension', HiddenType::class)
            ->add('send', SubmitType::class, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => 'button.rename.action',
            ])
            ->getForm();
    }

    /**
     * @Route("/profile/{username}/upload/", name="file_manager_upload")
     *
     * Using /profile/{username}/upload/ will crash the page because symfony doesn't maintain the username parameter.
     *
     * Get the configuration from URL (acceptable types, dir)
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function uploadFileAction(Request $request)
    {
        $fileSystem = new Filesystem();
        $fileManager = $this->newFileManager($request->query->all());

        $options = [
            'upload_dir' => $fileManager->getCurrentPath().DIRECTORY_SEPARATOR,
            'upload_url' => $fileManager->getImagePath(),
            'accept_file_types' => $fileManager->getRegex(),
            'print_response' => false,
        ];
        if (isset($fileManager->getConfiguration()['upload'])) {
            $options += $fileManager->getConfiguration()['upload'];
        }

        $this->dispatch(FileManagerEvents::PRE_UPDATE, ['options' => &$options]);

        $uploadHandler = new UploadHandler($options);
        $response = $uploadHandler->response;

        foreach ($response['files'] as $file) {
            if (isset($file->error)) {
                $file->error = $this->get('translator')->trans($file->error);
            }

            if (!$fileManager->getImagePath()) {
                $file->url = $this->generateUrl('file_manager_file', array_merge($fileManager->getQueryParameters(), ['fileName' => $file->url]));
            }
        }

        $this->dispatch(FileManagerEvents::POST_UPDATE, ['response' => &$response]);

        return new JsonResponse($response);
    }


}