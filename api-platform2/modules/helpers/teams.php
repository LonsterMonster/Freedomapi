<?php
if (!defined('ABSPATH')) exit;

/*
----------------------------------------
TEAM SYSTEM
----------------------------------------
*/
function apiplatform_get_team_owner($user_id){
    $owner = get_user_meta($user_id,'apiplatform_team_owner',true);
    return $owner ?: $user_id;
}

function apiplatform_get_team_members($owner_id){
    return get_user_meta($owner_id,'apiplatform_team_members',true) ?: [];
}

function apiplatform_add_team_member($owner_id,$user_id,$role='viewer'){
    $members = get_user_meta($owner_id,'apiplatform_team_members',true) ?: [];
    $members[$user_id] = $role;
    update_user_meta($owner_id,'apiplatform_team_members',$members);
    update_user_meta($user_id,'apiplatform_team_owner',$owner_id);
}

function apiplatform_remove_team_member($owner_id,$user_id){
    $members = get_user_meta($owner_id,'apiplatform_team_members',true) ?: [];
    unset($members[$user_id]);
    update_user_meta($owner_id,'apiplatform_team_members',$members);
    delete_user_meta($user_id,'apiplatform_team_owner');
}

/*
----------------------------------------
SEAT LIMIT
----------------------------------------
*/
function apiplatform_get_seat_limit($user_id){
    return class_exists('APIPlatform_Membership_Panel')
        ? APIPlatform_Membership_Panel::get_user_limit($user_id, 'seats')
        : 'Unlimited';
}
