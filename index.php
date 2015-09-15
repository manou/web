<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>Test websocket</title>
        <link rel="stylesheet" href="/static/dist/css/bootstrap.css">
        <link rel="stylesheet" href="/static/dist/css/bootstrap-theme.css">
        <script data-main="/static/js/app"
                src="/static/js/lib/vendors/require.js"
                type="text/javascript"
                charset="utf-8"
                async defer>
        </script>
    </head>
    <body>
        <h1>Test websocket</h1>
    
        <!-- register user -->
        <form action="user/register" method="post" accept-charset="utf-8" data-ajax="false">
            <input type="text" name="firstName" placeholder="<?=_('First name')?>">
            <input type="text" name="lastName" placeholder="<?=_('Last name')?>">
            <input type="text" name="pseudonym" placeholder="<?=_('Pseudonym')?>">
            <input type="email" name="email" placeholder="<?=_('Email')?>">
            <input type="password" name="password" placeholder="<?=_('Password')?>">
            <input type="submit" value="submit">
        </form>
        
        <!-- connect user -->
        <form action="user/connect" method="post" accept-charset="utf-8">
            <input type="text" name="login" placeholder="<?=_('Login (Pseudonym or email')?>">
            <input type="password" name="password" placeholder="<?=_('Password')?>">
            <input type="submit" value="submit">
        </form>

        <!-- connect room -->
        <div class="connect-room">
            <input class="pseudonym" type="text" name="chatPseudo" placeholder="<?=_('Pseudonym')?>">
            <input class="room-name" type="text" name="roomName" placeholder="<?=_('Room name')?>">
            <input class="room-password" type="password" name="roomPassword"  placeholder="<?=_('Room password')?>">
            <button class="connect btn btn-primary" type="button"><?=_('Connect')?></button>
        </div>
        
        <!-- create room -->
        <div class="create-room">
            <input class="room-name" type="text" name="roomName" value="" placeholder="<?=_('Room name')?>">
            <select class="room-type" name="roomType">
                <option value="public" selected><?=_('Public')?></option>
                <option value="private"><?=_('Private')?></option>
            </select>
            <input class="room-password" type="password" name="roomPassword" value="" placeholder="<?=_('Password')?>">
            <input class="room-max-users" type="number" name="roomMaxUsers" value="" placeholder="<?=_('Max users')?>">
            <button class="create" type="button"><?=_('Create a room')?></button>
        </div>

        <div id="chat">
            <!-- rooms -->
            <div id="room-sample"
                class="room hide"
                data-name=""
                data-type=""
                data-pseudonym=""
                data-users=""
                data-max-users=""
                data-password=""
            >
                <!-- room title -->
                <h3 class="header">
                    <span class="users glyphicon glyphicon-user pull-left"
                          type="button"
                          data-title="<?= _('Connected users')?>"
                          data-toggle="popover"
                          data-placement="right"
                          data-html="true"
                    ></span>
                    <span class="admin glyphicon glyphicon-cog pull-left"
                          data-toggle="modal"
                          data-target="#chat-admin-id"
                    ></span>
                    <span class="room-name"><?= _('default')?></span>
                    <span class="badge messages-unread"></span>
                    <span class="close-room pull-right glyphicon glyphicon-remove"></span>
                    <span class="fullscreen pull-right glyphicon glyphicon-fullscreen"></span>
                    <span class="minimize pull-right glyphicon glyphicon-minus"></span>
                </h3>
                <!-- room contents -->
                <div class="room-contents">
                    <!-- messages display -->
                    <div class="chat" data-historic-loaded="0">
                        <button class="load-historic" type="button"><?=_('Load more')?></button>
                    </div>
                    <!-- send message -->
                    <form class="send-action no-ajax">
                        <div class="form-group">
                            <textarea class="message form-control"
                                      rows="3"
                                      name="message"
                                      list="chatCommands"
                                      placeholder="<?=_('Message')?>"
                            ></textarea>
                        </div>
                        <div class="form-group">
                            <div class="input-group-btn dropup">
                                <button type="button"
                                        class="btn btn-default dropdown-toggle recievers"
                                        data-toggle="dropdown"
                                        data-value="all"
                                        aria-haspopup="true"
                                        aria-expanded="false"
                                ><?=_('Send to')?> (<span class="value"><?=_('All')?></span>) <span class="caret"></span>
                                </button>
                                <ul class="dropdown-menu users-list">
                                    <li data-value="all">
                                        <a href="#" title="<?=_('All')?>"><?=_('All')?></a>
                                    </li>
                                </ul>
                                <button class="send btn btn-default" type="submit"><?=_('Send message')?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- admin chat modal -->
        <div class="modal chat-admin"
             id="chat-admin-sample"
             tabindex="-1"
             role="dialog"
             aria-labelledby="<?=_('Chat administration')?>"
             data-room-name=""
        >
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <h4 class="modal-title"><?=_('Chat administration')?> "<span class="room-name"></span>"</h4>
                    </div>
                    <div class="modal-body">
                        <div class="panel panel-default">
                            <div class="panel-heading"><?=_('Users list')?></div>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><?=_('User pseudonym')?></th>
                                        <th colspan="3"><?=_('Actions')?></th>
                                    </tr>
                                </thead>
                                <tbody class="users-list">
                                    <tr class="hide sample" data-pseudonym="">
                                        <td class="user-pseudonym">Pseudonym</td>
                                        <td>
                                            <button class="btn btn-default kick" type="button"><?=_('Kick')?></button>
                                        </td>
                                        <td>
                                            <button class="btn btn-default ban" type="button"><?=_('Ban')?></button>
                                        </td>
                                        <td>
                                            <button class="toggle-rights btn btn-default" type="button" data-refer="">
                                                <?=_('Rights')?> <span class="caret"></span>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr class="hide sample right" data-pseudonym="">
                                        <td colspan="4" class="warper">
                                            <label><?=_('Kick users')?></label>
                                            <input type="checkbox"
                                                   name="kick"
                                                   data-on-color="success"
                                                   data-off-color="danger"
                                                   data-size="mini"
                                            >
                                        </td>
                                    </tr>
                                    <tr class="hide sample right" data-pseudonym="">
                                        <td colspan="4" class="warper">
                                            <label><?=_('Ban users')?></label>
                                            <input type="checkbox"
                                                   name="ban"
                                                   data-on-color="success"
                                                   data-off-color="danger"
                                                   data-size="mini"
                                            >
                                        </td>
                                    </tr>
                                    <tr class="hide sample right" data-pseudonym="">
                                        <td colspan="4" class="warper">
                                            <label><?=_('Grant users rights')?></label>
                                            <input type="checkbox"
                                                   name="grant"
                                                   data-on-color="success"
                                                   data-off-color="danger"
                                                   data-size="mini"
                                            >
                                        </td>
                                    </tr>
                                    <tr class="hide sample right" data-pseudonym="">
                                        <td colspan="4" class="warper">
                                            <label><?=_('Rename room name')?></label>
                                            <input type="checkbox"
                                                   name="rename"
                                                   data-on-color="success"
                                                   data-off-color="danger"
                                                   data-size="mini"
                                            >
                                        </td>
                                    </tr>
                                    <tr class="hide sample right" data-pseudonym="">
                                        <td colspan="4" class="warper">
                                            <label><?=_('Change room password')?></label>
                                            <input type="checkbox"
                                                   name="password"
                                                   data-on-color="success"
                                                   data-off-color="danger"
                                                   data-size="mini"
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="panel panel-default">
                            <div class="panel-heading"><?=_('Ip banned')?></div>
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><?=_('Ip banned')?></th>
                                        <th><?=_('Actions')?></th>
                                    </tr>
                                </thead>
                                <tbody class="ip-banned">
                                    <tr class="hide sample">
                                        <td class="ip">0.0.0.0</td>
                                        <td>
                                            <button class="btn btn-default unbanned" type="button">
                                                <?=_('unbanned')?>
                                            </button>
                                        </td>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>