var fmsObject;

$(function(){
    fmsObject = new _fms();
})

var _fms = function(){
    var that = this;
    var ui = {
        move: {
            button: $('button.js-move-modal'),
            modal: $('#moveFile'),
            current: $('#moveFile .current'),
            current_id: $('#moveFile .current_file_id'),
            new_parent_id: $('#moveFile .new_parent_id'),
            destBox: $('#moveFile .dest'),
        },
        share: {
            type_select: $('.type_select button'),
            table: $('.share_table'),
            modal_button: $('.share_button'),
            modal: $('#shareIndividualFolder'),
            submit: $('#shareIndividualFolder button.share')
        }
    }
    var data = {
        share: {
            users: [],
            roles: [],
            folder_id: false,
            type: false,
            existing: false,
        },
    }

    var init = function(){
        setListeners();
        createShareTable();
    }

    var setListeners = function(){
        ui.move.button.on('click',moveFile);
        ui.share.submit.on('click', shareFile)
        ui.share.type_select.on('click', switchShareType)
        ui.share.modal_button.on('click', getSharedUsersAndRoles)
    }

    var moveFile = function(){
        console.log($(this).data());
        var file_id = $(this).data('id');
        var file_name = `${$(this).data('name')}.${$(this).data('extension')}`;
        buildMoveSelect();     
        ui.move.current.val(file_name);
        ui.move.current_id.val(file_id);
        ui.move.modal.modal("show");
    }

    var shareFile = function(){
        console.log(data.share)
        data.share.folder_id = $('.jstree-clicked').attr('id');
        if((data.share.type == 'user' && data.share.users.length <= 0) || (data.share.type == 'role' && data.share.roles.length <= 0)){
            alert("Please select users to share this file with");
            return; 
        }
        $.ajax({
            url: '/fms/share',
            method: 'POST',
            data: data.share,
            success: function(data){
                console.log(data);
                ui.share.modal.modal('hide');
                location.reload();
            },
            error: function(error){
                console.log(error);
            }
        })
    }

    var buildMoveSelect = function(){
        var tree_array = getTreeArray([], treedata, '');
        var el = $(`<select class="target_folder" name="dest">`)
                .on('change', function(){
                    var new_parent_id = $(this).children("option:selected"). val();
                    ui.move.new_parent_id.val(new_parent_id);
                })
        if(tree_array.length > 0){
            ui.move.new_parent_id.val(tree_array[0].value);
        }
        for(var t of tree_array){
            $(`<option>`).text(t.text).val(t.value).appendTo(el);
        }
        console.log(el);
        ui.move.destBox.empty();
        ui.move.destBox.append(el);
    }

    var getTreeArray = function(tree_array = [], treedata, parent=''){
        for(var t of treedata){
            var path = `${parent}/${t.text}`;
            tree_array.push({
                text: path,
                value: t.a_attr.id
            })
            if(t.children && t.children.length > 0){
                getTreeArray(tree_array, t.children, path);
            }
        }
        return tree_array;
    }

    var getSharedUsersAndRoles = function(){
        var folder_id = $('.jstree-clicked').attr('id');
        $.ajax({
            url: `/fms/shared?existing=true&folder_id=${folder_id}`,
            method: 'GET',
            success: function(result){
                console.log(result);
                data.share.users = data.share.users.concat(result.users);
                data.share.roles = data.share.roles.concat(result.roles);
                markSelected();
            },
            error: function(error){
                console.log(error);
            }
        })
    }

    var switchShareType = function(event, default_type = false){
        var type;
        if(default_type)
            type = default_type;
        else
            type = $(this).data('type');
        $('.dataTables_wrapper').hide()
        $(`table.${type}`).closest('.dataTables_wrapper').show();
        ui.share.type_select.removeClass('selected');
        $(this).addClass('selected');
        data.share.type = type;
        markSelected();
        console.log(data);
    }

    var markSelected = function(){
        $('.share_table tbody tr').each(function(ndx, val){
            var id = $(val).data('id');
            var ndx = -1;
            if(data.share.type == 'user')
                ndx = data.share.users.indexOf(id);
            if(data.share.type == 'role')
                ndx = data.share.roles.indexOf(id);
            if(ndx != -1){
                $(val).addClass('selected');
            }
        });
    }

    var createShareTable = function(){
        ui.share.table.DataTable({
            "drawCallback": markSelected
        });
        ui.share.table.on('click', 'tbody tr', function () {
            var id = $(this).data('id');
            var ndx = -1;
            if(data.share.type == 'user')
                ndx = data.share.users.indexOf(id);
            if(data.share.type == 'role')
                ndx = data.share.roles.indexOf(id);
            if($(this).hasClass('selected')){
                $(this).removeClass('selected');
                if(ndx != -1){
                    if(data.share.type == 'user')
                        data.share.users.splice(ndx, 1);
                    if(data.share.type == 'role')
                        data.share.roles.splice(ndx, 1);
                }
            }
            else{
                $(this).addClass('selected');
                if(ndx == -1){
                    if(data.share.type == 'user')
                        data.share.users.push(id);
                    if(data.share.type == 'role')
                        data.share.roles.push(id);
                }
            }
            console.log(data.share);
        });
        switchShareType(null, 'user');
    }

    init();
}