<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
CREATE ALERT
----------------------------------------
*/
function apiplatform_create_alert($user_id, $message){

    add_user_meta($user_id, 'apiplatform_alert', [
        'message' => $message,
        'time'    => current_time('mysql')
    ]);

    // 🔥 EMAIL
    wp_mail(
        get_userdata($user_id)->user_email,
        'API Platform Alert',
        $message
    );
}
function apiplatform_send_email($user_id,$message){

    $user = get_userdata($user_id);

    wp_mail(
        $user->user_email,
        'API Platform',
        $message
    );
}
/*
----------------------------------------
SEND NOTIFICATION (CORE)
----------------------------------------
*/
function apiplatform_notify($user_id, $message, $channels = ['email']){

    $settings = get_option('apiplatform_notifications', []);

    foreach($channels as $channel){

        switch($channel){

            case 'email':
                apiplatform_notify_email($user_id, $message);
                break;

            case 'sms':
                apiplatform_notify_sms($user_id, $message);
                break;

            case 'push':
                apiplatform_notify_push($user_id, $message);
                break;

            case 'internal':
                apiplatform_notify_internal($user_id, $message);
                break;
        }
    }
}
function apiplatform_notify_email($user_id,$message){

    $user = get_userdata($user_id);

    wp_mail(
        $user->user_email,
        'API Platform Notification',
        $message
    );
}
function apiplatform_notify_sms($user_id,$message){

    $phone = get_user_meta($user_id,'phone_number',true);
    if (!$phone) return;

    $sid   = get_option('apiplatform_twilio_sid');
    $token = get_option('apiplatform_twilio_token');
    $from  = get_option('apiplatform_twilio_number');

    if (!$sid || !$token || !$from) return;

    wp_remote_post("https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json",[
        'body'=>[
            'From'=>$from,
            'To'=>$phone,
            'Body'=>$message
        ],
        'headers'=>[
            'Authorization'=>'Basic '.base64_encode("$sid:$token")
        ]
    ]);
}
function apiplatform_notify_push($user_id,$message){

    $provider = get_option('apiplatform_push_provider','internal');

    switch($provider){

        case 'firebase':
            apiplatform_push_firebase($user_id,$message);
            break;

        case 'pusher':
            apiplatform_push_pusher($user_id,$message);
            break;

        default:
            apiplatform_notify_internal($user_id,$message);
            break;
    }
}
function apiplatform_notify_internal($user_id,$message){

    add_user_meta($user_id,'apiplatform_notification',[
        'message'=>$message,
        'time'=>current_time('mysql')
    ]);
}
function apiplatform_push_firebase($user_id,$message){

    $token = get_user_meta($user_id,'firebase_token',true);
    $server_key = get_option('apiplatform_firebase_key');

    if (!$token || !$server_key) return;

    wp_remote_post('https://fcm.googleapis.com/fcm/send',[
        'headers'=>[
            'Authorization'=>'key='.$server_key,
            'Content-Type'=>'application/json'
        ],
        'body'=>json_encode([
            'to'=>$token,
            'notification'=>[
                'title'=>'API Platform',
                'body'=>$message
            ]
        ])
    ]);
}
function apiplatform_push_pusher($user_id,$message){

    $app_id = get_option('apiplatform_pusher_app');
    $key    = get_option('apiplatform_pusher_key');

    if (!$app_id || !$key) return;

    wp_remote_post("https://api.pusherapp.com/apps/$app_id/events",[
        'body'=>json_encode([
            'name'=>'notification',
            'channel'=>'user_'.$user_id,
            'data'=>json_encode(['message'=>$message])
        ])
    ]);
}
function apiplatform_is_twilio_ready(){
    return get_option('apiplatform_twilio_sid') &&
           get_option('apiplatform_twilio_token') &&
           get_option('apiplatform_twilio_number');
}

function apiplatform_is_firebase_ready(){
    return get_option('apiplatform_firebase_key');
}

function apiplatform_is_pusher_ready(){
    return get_option('apiplatform_pusher_app') &&
           get_option('apiplatform_pusher_key');
}
