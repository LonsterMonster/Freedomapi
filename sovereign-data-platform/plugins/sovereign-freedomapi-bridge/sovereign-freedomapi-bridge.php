<?php
/**
 * Plugin Name: Sovereign FreedomAPI Bridge
 * Description: Personal data runtime for the existing FreedomAPI Core gateway, keys and rate limits.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
if(!defined('ABSPATH'))exit;
final class Sovereign_FreedomAPI_Bridge {
 static function compatible(){return class_exists('APIPlatform_Gateway')&&defined('APIPlatform_Gateway::SENSITIVE_DATA_INTERFACE')&&class_exists('APIPlatform_Endpoint_Schema_Service')&&function_exists('freedomapi_can_manage_api');}
 static function boot(){add_action('plugins_loaded',function(){if(self::compatible())APIPlatform_Gateway::register_callback('sovereign',[__CLASS__,'run']);},35);add_action('admin_menu',fn()=>add_options_page('Sovereign FreedomAPI Bridge','Sovereign Bridge','manage_options','sovereign-bridge',[__CLASS__,'settings']));}
 static function schema($version){$endpoints=[];foreach([['token','POST'],['userinfo','GET'],['data','GET'],['data','POST'],['revoke','POST']] as [$path,$method])$endpoints[]=['path'=>'/'.$path,'method'=>$method,'summary'=>'Sovereign '.$path,'auth_required'=>true,'authentication'=>'FreedomAPI API key + central user consent','parameters'=>[],'request_body'=>['enabled'=>false],'responses'=>[]];return ['version'=>$version,'endpoints'=>$endpoints];}
 static function configure($api,$host){
 if(!self::compatible())return new WP_Error('core_required','Install the included Core privacy patches first.');
 if(!freedomapi_can_manage_api($api))return new WP_Error('permission_denied','You cannot manage this API.');
 if(!filter_var($host,FILTER_VALIDATE_URL)||wp_parse_url($host,PHP_URL_SCHEME)!=='https'||wp_parse_url($host,PHP_URL_USER)||wp_parse_url($host,PHP_URL_PASS)||wp_parse_url($host,PHP_URL_QUERY)||wp_parse_url($host,PHP_URL_FRAGMENT))return new WP_Error('invalid_host','Use the HTTPS root URL of the data WordPress site.');
 if(function_exists('freedomapi_has_response_transformation')&&freedomapi_has_response_transformation($api))return new WP_Error('transformation_active','Use a dedicated API without an active AI transformation.');
 $version=APIPlatform_Endpoint_Schema_Service::version_label($api);$result=APIPlatform_Endpoint_Schema_Service::save_schema($api,get_current_user_id(),self::schema($version));if(empty($result['ok']))return new WP_Error('schema_failed',$result['message']??'Could not save schema.');
 // Sensitive flag precedes runtime activation and persists if this extension is disabled.
 update_post_meta($api,'_freedomapi_sensitive_data_api',1);update_post_meta($api,'apiplatform_gateway_cache_enabled',0);update_post_meta($api,'apiplatform_gateway_runtime_type','sovereign');update_post_meta($api,'_sovereign_host',untrailingslashit($host));update_option('sv_native_api_id',$api,false);return true;
 }
 static function settings(){
 if(!current_user_can('manage_options'))return;echo '<div class="wrap"><h1>Sovereign FreedomAPI Bridge</h1><p>This uses your existing gateway, API keys, ownership rules, rate limits and request status history.</p>';
 if(!self::compatible()){echo '<p>Install the included Core privacy patches before enabling this bridge.</p></div>';return;}
 if($_SERVER['REQUEST_METHOD']==='POST'){check_admin_referer('sv_native_setup');$api=absint($_POST['api']??0);$result=self::configure($api,esc_url_raw(wp_unslash($_POST['host']??'')));echo '<p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Configured. Publish this dedicated API using your existing Core controls and authorize a platform key for it.').'</p>';}
 $api=absint(get_option('sv_native_api_id',0));echo '<p>Create a new dedicated API in FreedomAPI first. This action replaces the selected API’s endpoint schema with the Sovereign endpoints. It does not publish the API or issue keys.</p><form method="post">';wp_nonce_field('sv_native_setup');echo '<p><label>Dedicated FreedomAPI API post ID <input type="number" min="1" name="api" required value="'.esc_attr($api?:'').'"></label></p><p><label>Data-hosting WordPress root URL <input type="url" name="host" required size="80" value="'.esc_attr($api?get_post_meta($api,'_sovereign_host',true):'').'"></label></p>';submit_button('Configure this dedicated data API');echo '</form>';
 if($api&&freedomapi_can_manage_api($api)){echo '<p>Use the gateway URL displayed by Core for this API, append its version label, and enter that base in the Connector. Example: <code>https://www.freedomapi.net/gateway/PUBLISHER/API/v1</code>.</p><p>Registered runtime: <code>sovereign</code>. Cache is disabled. Request History retains status/timing without personal payloads.</p>';}
 echo '</div>';
 }
 static function run($r){
 $error=fn($code,$message,$status)=>new APIPlatform_Gateway_Error($code,$message,$status);
 if(!is_ssl())return $error('https_required','HTTPS required.',403);
 if(!get_post_meta($r->api_id,'_freedomapi_sensitive_data_api',true))return $error('privacy_required','Data API privacy flag is missing.',503);
 $host=get_post_meta($r->api_id,'_sovereign_host',true);if(!$host)return $error('host_missing','Data host not configured.',503);
 if($r->source!=='gateway')return $error('route_required','Use the native FreedomAPI public gateway URL.',404);
 $tail=explode('/',trim((string)($r->route_parts[2]??''),'/'));if(($tail[0]??'')===$r->version)array_shift($tail);$path=implode('/',$tail);$allowed=['token'=>['POST'],'userinfo'=>['GET'],'data'=>['GET','POST'],'revoke'=>['POST']];
 if(!isset($allowed[$path]))return $error('endpoint_not_found','Unknown data endpoint.',404);if(!in_array($r->method,$allowed[$path],true))return $error('method_not_allowed','Method not allowed.',405);
 if(function_exists('freedomapi_has_response_transformation')&&freedomapi_has_response_transformation($r->api_id))return $error('transformation_not_supported','Disable response transformations on this data API.',503);
 $headers=[];foreach($r->headers as $k=>$v)$headers[strtolower($k)]=$v;
 $args=['method'=>$r->method,'redirection'=>0,'timeout'=>15,'limit_response_size'=>65536,'headers'=>['Accept'=>'application/json']];
 if($path!=='token'){$token=$headers['x-access-token']??'';if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/',$token))return $error('user_token_required','Central user token required in X-Access-Token.',401);$args['headers']['Authorization']='Bearer '.$token;}
 $url=$host.'/wp-json/sovereign/v1/'.$path;
 if($r->method==='GET'){if(isset($r->query['fields'])){$fields=$r->query['fields'];if(!is_string($fields))return $error('invalid_fields','Fields must be a comma-separated string.',400);$url=add_query_arg('fields',sanitize_text_field($fields),$url);}}
 else{if(strlen($r->body)>16000||!is_array($r->json))return $error('invalid_body','Send a JSON object, at most 16 KB.',400);$keys=$path==='token'?['grant_type','client_id','client_secret','redirect_uri','code','code_verifier']:($path==='data'?['data','revision']:[]);$body=array_intersect_key($r->json,array_fill_keys($keys,true));$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode((object)$body);}
 $up=wp_safe_remote_request($url,$args);if(is_wp_error($up))return $error('data_host_unavailable','Data host unavailable.',502);$status=wp_remote_retrieve_response_code($up);$body=json_decode(wp_remote_retrieve_body($up),true);if(!is_array($body)||$status<200||$status>=500)return $error('invalid_upstream','Data host returned an invalid response.',502);
 return new APIPlatform_Gateway_Response($body,$status,['Cache-Control'=>'no-store','Pragma'=>'no-cache']);
 }
}
Sovereign_FreedomAPI_Bridge::boot();
