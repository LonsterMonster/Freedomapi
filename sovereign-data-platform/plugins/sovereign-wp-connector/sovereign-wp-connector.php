<?php
/**
 * Plugin Name: Sovereign WordPress Connector
 * Description: Sign in using a central Sovereign data site, and sync only user-approved data through a configurable API relay.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
if(!defined('ABSPATH'))exit;
final class Sovereign_Connector {
 static function boot(){
 add_action('admin_menu',fn()=>add_options_page('Sovereign Connector','Sovereign Connector','manage_options','sovereign-connector',[__CLASS__,'settings']));
 foreach(['admin_post_sv_connect','admin_post_nopriv_sv_connect'] as $hook)add_action($hook,[__CLASS__,'start']);
 foreach(['admin_post_sv_callback','admin_post_nopriv_sv_callback'] as $hook)add_action($hook,[__CLASS__,'callback']);
 add_action('admin_post_sv_sync',[__CLASS__,'sync']);add_shortcode('sovereign_connect',[__CLASS__,'shortcode']);
 }
 static function config(){return get_option('sv_connector',[]);}
 static function key(){return hash('sha256',wp_salt('auth'),true);}
 static function encrypt($value){$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Secret encryption failed.');return base64_encode($iv.$tag.$cipher);}
 static function decrypt($value){$raw=base64_decode($value,true);if(!$raw||strlen($raw)<29)return '';return openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16))?:'';}
 static function url($url){return filter_var($url,FILTER_VALIDATE_URL)&&wp_parse_url($url,PHP_URL_SCHEME)==='https'&&!wp_parse_url($url,PHP_URL_QUERY)&&!wp_parse_url($url,PHP_URL_FRAGMENT)&&!wp_parse_url($url,PHP_URL_USER)&&!wp_parse_url($url,PHP_URL_PASS);}
 static function settings(){
 if(!current_user_can('manage_options'))return;$c=self::config();echo '<div class="wrap"><h1>Sovereign WordPress Connector</h1>';
 if($_SERVER['REQUEST_METHOD']==='POST'){
 check_admin_referer('sv_connector');$host=trailingslashit(esc_url_raw(wp_unslash($_POST['host']??'')));$api=untrailingslashit(esc_url_raw(wp_unslash($_POST['api']??'')));$id=sanitize_text_field(wp_unslash($_POST['client_id']??''));$secret=trim(wp_unslash($_POST['secret']??''));$gateway=trim(wp_unslash($_POST['gateway_key']??''));$requested=array_values(array_intersect(['identity','read:profile','read:interests','read:location','read:shopping','write:profile','write:interests','write:location','write:shopping'],array_map('sanitize_text_field',(array)($_POST['scopes']??[]))));
 if(!self::url($host)||!self::url($api)||!preg_match('/^sv_[a-f0-9]{24}$/',$id)||(!$secret&&empty($c['secret']))||(!$gateway&&empty($c['gateway_key']))||($secret&&!preg_match('/^[a-f0-9]{64}$/',$secret)))echo '<p>Enter valid HTTPS URLs, a registered client ID, and a valid client secret.</p>';
 elseif(!$secret&&($host!==($c['host']??'')||$id!==($c['client_id']??'')))echo '<p>Enter the new client secret when changing the identity server or client ID.</p>';
 else{$c=['host'=>$host,'api'=>$api,'client_id'=>$id,'secret'=>$secret?self::encrypt($secret):$c['secret'],'gateway_key'=>$gateway?self::encrypt($gateway):$c['gateway_key'],'scopes'=>array_values(array_unique(array_merge(['identity'],$requested)))];update_option('sv_connector',$c,false);echo '<p>Saved. Users must reconnect after settings change.</p>';}
 }
 echo '<p>Callback URL — copy this exactly into the data host application registration:<br><code>'.esc_html(admin_url('admin-post.php?action=sv_callback')).'</code></p><p>Put <code>[sovereign_connect]</code> on a page to enable sign-in and user-initiated syncing.</p><form method="post">';wp_nonce_field('sv_connector');
 foreach(['host'=>'Data site root URL','api'=>'FreedomAPI native gateway API base (with version)','client_id'=>'Client ID'] as $k=>$label)echo '<p><label>'.esc_html($label).' <input name="'.esc_attr($k).'" size="80" required value="'.esc_attr($c[$k]??'').'"></label></p>';
 echo '<p><label>FreedomAPI platform API key <input type="password" name="gateway_key" autocomplete="new-password" placeholder="Leave blank to keep existing key"></label></p>';
 echo '<p><label>Client secret <input type="password" name="secret" autocomplete="new-password" placeholder="Leave blank to keep existing secret"></label></p><p>Scopes requested (users still select what to approve):</p>';
 foreach(['identity','read:profile','read:interests','read:location','read:shopping','write:profile','write:interests','write:location','write:shopping'] as $s)echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="'.esc_attr($s).'" '.checked(in_array($s,$c['scopes']??['identity'],true),true,false).'> '.esc_html($s).'</label>';
 submit_button('Save connector');echo '</form><p>HTTPS and PHP OpenSSL are required. Passwords and bearer tokens are never exposed in page scripts.</p></div>';
 }
 static function fingerprint($c){return hash('sha256',($c['host']??'').'|'.($c['api']??'').'|'.($c['client_id']??'').'|'.implode(' ',$c['scopes']??[]).'|'.($c['gateway_key']??''));}
 static function request($path,$method='GET',$body=null,$token=''){
 $c=self::config();if(empty($c['api']))return new WP_Error('config','Configure the connector first.');$args=['method'=>$method,'timeout'=>15,'redirection'=>0,'limit_response_size'=>65536,'headers'=>['Accept'=>'application/json']];if(empty($c['gateway_key']))return new WP_Error('config','Enter your FreedomAPI platform API key.');$key=self::decrypt($c['gateway_key']);if(!$key)return new WP_Error('config','Re-enter your FreedomAPI key.');$args['headers']['Authorization']='Bearer '.$key;if($token)$args['headers']['X-Access-Token']=$token;
 if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}
 $result=wp_safe_remote_request($c['api'].'/'.$path,$args);if(is_wp_error($result))return new WP_Error('network','API connection failed. Try again.');$status=wp_remote_retrieve_response_code($result);$json=json_decode(wp_remote_retrieve_body($result),true);if(!is_array($json)||$status<200||$status>=300)return new WP_Error('api',is_array($json)?sanitize_text_field($json['message']??(is_array($json['error']??null)?($json['error']['message']??'Access denied.'):($json['error']??'Access denied.'))): 'API returned an invalid response.',['status'=>$status]);return $json;
 }
 static function start(){
 check_admin_referer('sv_connect');$c=self::config();if(!is_ssl()||empty($c['host'])||empty($c['client_id'])||empty($c['secret']))wp_die('Configure HTTPS and connector settings first.');
 $state=bin2hex(random_bytes(32));$verifier=bin2hex(random_bytes(32));$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$key='sv_state_'.hash('sha256',$state);
 $return=wp_validate_redirect(wp_get_referer(),home_url('/'));$ok=set_transient($key,['verifier'=>$verifier,'uid'=>get_current_user_id(),'return'=>$return,'fingerprint'=>self::fingerprint($c)],600);if(!$ok)wp_die('Unable to start authorization.');
 setcookie('sv_oauth_state',$state,['expires'=>time()+600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
 $url=add_query_arg(['action'=>'sv_authorize','response_type'=>'code','client_id'=>$c['client_id'],'redirect_uri'=>admin_url('admin-post.php?action=sv_callback'),'state'=>$state,'scope'=>implode(' ',$c['scopes']),'code_challenge'=>$challenge,'code_challenge_method'=>'S256'],$c['host'].'wp-admin/admin-post.php');wp_redirect($url);exit;
 }
 static function callback(){
 nocache_headers();if(!is_ssl())wp_die('HTTPS required.');$state=sanitize_text_field(wp_unslash($_GET['state']??''));$cookie=sanitize_text_field(wp_unslash($_COOKIE['sv_oauth_state']??''));
 if(!preg_match('/^[a-f0-9]{64}$/',$state)||!hash_equals($state,$cookie))wp_die('Sign-in state did not match. Start again in this browser.');
 $key='sv_state_'.hash('sha256',$state);$pending=get_transient($key);if(!$pending)wp_die('Sign-in expired. Start again.');delete_transient($key);setcookie('sv_oauth_state','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
 $c=self::config();if(!hash_equals($pending['fingerprint'],self::fingerprint($c))||(int)$pending['uid']!==get_current_user_id())wp_die('Account or settings changed. Start again.');
 if(isset($_GET['error'])){wp_safe_redirect(add_query_arg('sv_notice','declined',$pending['return']));exit;}
 $code=sanitize_text_field(wp_unslash($_GET['code']??''));if(!preg_match('/^[a-f0-9]{64}$/',$code))wp_die('Missing authorization code.');
 $token=self::request('token','POST',['grant_type'=>'authorization_code','client_id'=>$c['client_id'],'client_secret'=>self::decrypt($c['secret']),'redirect_uri'=>admin_url('admin-post.php?action=sv_callback'),'code'=>$code,'code_verifier'=>$pending['verifier']]);if(is_wp_error($token))wp_die(esc_html($token->get_error_message()));
 if(($token['token_type']??'')!=='Bearer'||!preg_match('/^[a-f0-9]{64}$/',$token['access_token']??'')||empty($token['expires_in']))wp_die('Invalid token response.');
 $identity=self::request('userinfo','GET',null,$token['access_token']);if(is_wp_error($identity))wp_die(esc_html($identity->get_error_message()));if(($identity['issuer']??'')!==$c['host']||!preg_match('/^[a-f0-9]{64}$/',$identity['sub']??''))wp_die('Unexpected identity server.');
 $external=hash('sha256',$identity['issuer'].'|'.$identity['sub']);$username='sv_'.substr($external,0,40);$linked=get_user_by('login',$username);
 if($pending['uid']){
 $uid=(int)$pending['uid'];$existing=get_user_meta($uid,'sv_identity',true);if($existing&&$existing!==$external)wp_die('This WordPress account is linked to a different central identity.');
 $matches=get_users(['meta_key'=>'sv_identity','meta_value'=>$external,'number'=>2,'fields'=>'ID']);if($matches&&array_diff(array_map('intval',$matches),[$uid]))wp_die('This central identity is already linked to another WordPress account.');
 }else{
 $matches=get_users(['meta_key'=>'sv_identity','meta_value'=>$external,'number'=>2,'fields'=>'ID']);if(count($matches)>1)wp_die('Duplicate identity links require administrator review.');
 if($matches)$uid=(int)$matches[0];elseif($linked){if(get_user_meta($linked->ID,'sv_identity',true)!==$external)wp_die('Reserved username conflict.');$uid=$linked->ID;}else{
 $uid=wp_insert_user(['user_login'=>$username,'user_pass'=>wp_generate_password(64,true,true),'display_name'=>sanitize_text_field($identity['name']??'Sovereign user'),'role'=>'subscriber']);if(is_wp_error($uid))wp_die(esc_html($uid->get_error_message()));
 }
 if(user_can($uid,'manage_options'))wp_die('Administrator accounts must sign in locally before linking.');
 }
 update_user_meta($uid,'sv_identity',$external);update_user_meta($uid,'sv_connection',['token'=>self::encrypt($token['access_token']),'expires'=>time()+min((int)$token['expires_in'],7*DAY_IN_SECONDS),'scope'=>explode(' ',(string)($token['scope']??'identity')),'fingerprint'=>self::fingerprint($c)]);delete_user_meta($uid,'sv_cache');
 wp_set_current_user($uid);wp_set_auth_cookie($uid,false,true);wp_safe_redirect(add_query_arg('sv_notice','connected',$pending['return']));exit;
 }
 static function connection(){if(!is_user_logged_in())return null;$conn=get_user_meta(get_current_user_id(),'sv_connection',true);if(!$conn||$conn['expires']<=time()||!hash_equals($conn['fingerprint'],self::fingerprint(self::config())))return null;return $conn;}
 static function sync(){
 if(!is_user_logged_in())wp_die('Sign in first.');check_admin_referer('sv_sync');$uid=get_current_user_id();$conn=self::connection();if(!$conn)wp_die('Reconnect your data account.');$token=self::decrypt($conn['token']);if(!$token)wp_die('Unable to read token. Reconnect.');$op=sanitize_text_field(wp_unslash($_POST['operation']??''));
 if($op==='disconnect'){$res=self::request('revoke','POST',[],$token);if(is_wp_error($res)){delete_user_meta($uid,'sv_cache');wp_die('Could not revoke remotely. Revoke on the data site or retry.');}delete_user_meta($uid,'sv_connection');delete_user_meta($uid,'sv_cache');}
 elseif($op==='collect'){
 $fields=[];foreach(['profile','interests','location','shopping'] as $f)if(in_array('write:'.$f,$conn['scope'],true)&&isset($_POST['data'][$f])){$value=wp_unslash($_POST['data'][$f]);if(!is_string($value)||strlen($value)>2000)wp_die('Invalid data value.');$fields[$f]=sanitize_textarea_field($value);}
 if(!$fields)wp_die('No approved collection fields.');$revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);if($revision===false||$revision===null)wp_die('Read current data before collecting an update.');
 $res=self::request('data','POST',['data'=>$fields,'revision'=>$revision],$token);delete_user_meta($uid,'sv_cache');if(is_wp_error($res))wp_die(esc_html($res->get_error_message()));
 }elseif($op==='read'){$res=self::request('data','GET',null,$token);if(is_wp_error($res)){delete_user_meta($uid,'sv_cache');wp_die(esc_html($res->get_error_message()));}update_user_meta($uid,'sv_cache',['data'=>$res['data'],'revision'=>$res['revision'],'fetched'=>time()]);do_action('sovereign_data_synced',$uid,$res['data'],$res['revision']);}
 else wp_die('Unknown sync action.');wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;
 }
 static function shortcode(){
 nocache_headers();ob_start();$conn=self::connection();echo '<section class="sv-connector"><h2>Your connected data account</h2>';
 if(!$conn){echo '<p>Sign in on the data site and choose which data this site may read or collect.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_connect');echo '<input type="hidden" name="action" value="sv_connect"><button>'.(is_user_logged_in()?'Connect my data account':'Sign in with my data account').'</button></form>';}
 else{
 echo '<p>Approved permissions: '.esc_html(implode(', ',$conn['scope'])).'.</p><p>This site collects only the text you explicitly submit below. No browsing or purchase tracking is enabled.</p>';
 // Validate live access on each render; do not display stale cached data after revocation.
 $current=self::request('data','GET',null,self::decrypt($conn['token']));$readable=!is_wp_error($current);if(!$readable){delete_user_meta(get_current_user_id(),'sv_cache');echo '<p>'.esc_html($current->get_error_message()).' If permission was revoked, reconnect.</p>';}
 else{echo '<h3>Approved data from your vault</h3><dl>';foreach($current['data'] as $f=>$value)echo '<dt>'.esc_html(ucfirst($f)).'</dt><dd>'.esc_html($value?:'(empty)').'</dd>';echo '</dl>';}
 echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_sync');echo '<input type="hidden" name="action" value="sv_sync"><button name="operation" value="read">Sync approved data now</button> <button name="operation" value="disconnect">Disconnect and revoke</button></form>';
 $writable=array_values(array_filter(['profile','interests','location','shopping'],fn($f)=>in_array('write:'.$f,$conn['scope'],true)));
 if($writable&&$readable){echo '<h3>Collect an update with your approval</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_sync');echo '<input type="hidden" name="action" value="sv_sync"><input type="hidden" name="revision" value="'.(int)$current['revision'].'">';foreach($writable as $f)echo '<label style="display:block;margin:15px 0">'.esc_html(ucfirst($f)).'<textarea style="display:block;width:100%" maxlength="500" name="data['.esc_attr($f).']">'.esc_textarea($current['data'][$f]??'').'</textarea></label>';echo '<button name="operation" value="collect">Send this update to my data vault</button></form>';}
 elseif($writable)echo '<p>Reconnect or retry to obtain the current revision before collecting updates.</p>';
 }
 echo '</section>';return ob_get_clean();
 }
}
Sovereign_Connector::boot();

/** Read the current local user's live, centrally approved data for integrations. */
function sovereign_get_current_user_data(){
 $conn=Sovereign_Connector::connection();
 if(!$conn)return new WP_Error('not_connected','Connect your data account first.');
 return Sovereign_Connector::request('data','GET',null,Sovereign_Connector::decrypt($conn['token']));
}
