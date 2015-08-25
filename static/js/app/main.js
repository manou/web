/*global define, window*/

define(
    ['jquery', 'message', 'forms', 'websocket', 'user', 'chat'],
    function ($, Message, FormsManager, WebsocketManager, User, ChatManager) {
        'use strict';

        var message   = new Message(),
            forms     = new FormsManager(message),
            user      = new User(message, forms),
            websocket = new WebsocketManager(message, user),
            chat      = new ChatManager(message, websocket, user);

        // Make it global to develop
        window.WebsocketManager = websocket;
        window.ChatManager      = chat;
    }
);