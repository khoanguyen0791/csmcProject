docker-compose exec php php bin/console doctrine:database:Drop --force
docker-compose exec php php bin/console doctrine:database:create
docker-compose exec php php bin/console doctrine:schema:update --force
docker-compose exec php php bin/console doctrine:fixtures:load
docker-compose exec php php bin/console app:add-user axs170032 developer