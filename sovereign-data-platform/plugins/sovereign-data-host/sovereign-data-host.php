<?php
/**
 * Plugin Name: Sovereign Data Host
 * Description: Central WordPress identity, consent and personal data store for Sovereign connectors.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) exit;
final class Sovereign_Host {
 const NS='sovereign/v1';
 static function table($name){global $wpdb;return $wpdb->prefix.'sv_'.$name;}
 static function random(){return bin2hex(random_bytes(32));}
 static function hash($v){return hash('sha256',$v);}
 static function err($msg,$status=400){return new WP_Error('sovereign_error',$msg,['status'=>$status]);}
 static function fields(){return ['profile','interests','location','shopping'];}
 static function scopes(){return array_merge(['identity'],array_map(fn($v)=>'read:'.$v,self::fields()),array_map(fn($v)=>'write:'.$v,self::fields()));}
 static function activate(){
 global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';$c=$wpdb->get_charset_collate();
 self::install_schema('CREATE TABLE '.self::table('codes')." (id varchar(64) NOT NULL, user_id bigint unsigned NOT NULL, client_id varchar(64) NOT NULL, redirect_uri text NOT NULL, challenge varchar(128) NOT NULL, grant_id varchar(64) NOT NULL, expires bigint NOT NULL, PRIMARY KEY  (id)) $c;");
 self::install_schema('CREATE TABLE '.self::table('grants')." (id varchar(64) NOT NULL, user_id bigint unsigned NOT NULL, client_id varchar(64) NOT NULL, scopes text NOT NULL, expires bigint NOT NULL, revoked tinyint NOT NULL DEFAULT 0, PRIMARY KEY  (id), KEY user_id (user_id)) $c;");
 self::install_schema('CREATE TABLE '.self::table('tokens')." (id varchar(64) NOT NULL, grant_id varchar(64) NOT NULL, client_id varchar(64) NOT NULL, expires bigint NOT NULL, PRIMARY KEY  (id), KEY grant_id (grant_id)) $c;");
 self::install_schema('CREATE TABLE '.self::table('vaults')." (user_id bigint unsigned NOT NULL, payload longtext NOT NULL, revision bigint NOT NULL DEFAULT 0, PRIMARY KEY  (user_id)) $c;");
 self::install_schema('CREATE TABLE '.self::table('events')." (id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, client_id varchar(64) NOT NULL, event varchar(40) NOT NULL, detail text NOT NULL, created bigint NOT NULL, PRIMARY KEY  (id), KEY user_created (user_id,created)) $c;");
 if(!wp_next_scheduled('sv_cleanup'))wp_schedule_event(time()+3600,'daily','sv_cleanup');
 }
 static function install_schema($sql){$sql=preg_replace('/ \(/', " (\n", $sql, 1);$sql=str_replace(', ',",\n",$sql);dbDelta($sql);}
 static function cleanup(){global $wpdb;$now=time();foreach(['codes','tokens'] as $n)$wpdb->query($wpdb->prepare('DELETE FROM '.self::table($n).' WHERE expires < %d',$now));$wpdb->query($wpdb->prepare('DELETE FROM '.self::table('events').' WHERE created < %d',$now-90*DAY_IN_SECONDS));}
 static function event($uid,$cid,$event,$detail){global $wpdb;$wpdb->insert(self::table('events'),['user_id'=>$uid,'client_id'=>$cid,'event'=>$event,'detail'=>$detail,'created'=>time()]);}
 static function boot(){
 register_activation_hook(__FILE__,[__CLASS__,'activate']);register_deactivation_hook(__FILE__,fn()=>wp_clear_scheduled_hook('sv_cleanup'));
 add_action('sv_cleanup',[__CLASS__,'cleanup']);add_action('rest_api_init',[__CLASS__,'routes']);
 add_action('admin_menu',fn()=>add_options_page('Sovereign Host','Sovereign Host','manage_options','sovereign-host',[__CLASS__,'settings']));
 foreach(['admin_post_sv_authorize','admin_post_nopriv_sv_authorize'] as $hook)add_action($hook,[__CLASS__,'authorize']);
 add_shortcode('sovereign_vault',[__CLASS__,'dashboard']);
 add_action('admin_post_sv_vault',[__CLASS__,'vault_save']);add_action('admin_post_sv_revoke',[__CLASS__,'revoke_form']);
 add_action('admin_post_sv_export',[__CLASS__,'export']);
 }
 static function client($id){$apps=get_option('sv_clients',[]);return $apps[$id]??null;}
 static function secure_url($url){return filter_var($url,FILTER_VALIDATE_URL)&&wp_parse_url($url,PHP_URL_SCHEME)==='https'&&!wp_parse_url($url,PHP_URL_USER)&&!wp_parse_url($url,PHP_URL_PASS)&&!wp_parse_url($url,PHP_URL_FRAGMENT);}
 static function settings(){
 if(!current_user_can('manage_options'))return;
 echo '<div class="wrap"><h1>Sovereign Data Host</h1><p>Place <code>[sovereign_vault]</code> on a page for user data and consent management. Use HTTPS on all three sites.</p>';
 if($_SERVER['REQUEST_METHOD']==='POST'){
 check_admin_referer('sv_clients');$apps=get_option('sv_clients',[]);
 if(isset($_POST['disable'])){unset($apps[sanitize_text_field(wp_unslash($_POST['disable']))]);update_option('sv_clients',$apps,false);echo '<p>Application disabled. Existing tokens can no longer access data.</p>';}
 else{
 $uri=trim(wp_unslash($_POST['redirect_uri']??''));$name=sanitize_text_field(wp_unslash($_POST['name']??''));$purpose=sanitize_textarea_field(wp_unslash($_POST['purpose']??''));$allowed=array_values(array_intersect(self::scopes(),array_map('sanitize_text_field',(array)($_POST['allowed']??[]))));
 if(!self::secure_url($uri)||!$name||!$purpose||!in_array('identity',$allowed,true)){echo '<p>Enter a valid HTTPS callback, name, purpose and identity permission.</p>';}
 else{$id='sv_'.substr(self::random(),0,24);$secret=self::random();$apps[$id]=['name'=>$name,'purpose'=>$purpose,'redirect_uri'=>$uri,'secret_hash'=>self::hash($secret),'allowed'=>$allowed];update_option('sv_clients',$apps,false);echo '<div class="notice notice-success"><p>Save these credentials now. The secret is displayed once.</p><p>Client ID: <code>'.esc_html($id).'</code><br>Client secret: <code>'.esc_html($secret).'</code></p></div>';}
 }
 }
 echo '<p>Data host URL: <code>'.esc_html(home_url('/')).'</code><br>REST base: <code>'.esc_html(rest_url(self::NS)).'</code></p><h2>Register a connected site</h2><form method="post">';wp_nonce_field('sv_clients');
 echo '<p><label>Site name <input name="name" required></label></p><p><label>Purpose <textarea name="purpose" required></textarea></label></p><p><label>Exact connector callback URL <input type="url" name="redirect_uri" size="80" required></label></p>';
 foreach(self::scopes() as $s)echo '<label style="display:block"><input type="checkbox" name="allowed[]" value="'.esc_attr($s).'" '.checked($s==='identity',true,false).'> '.esc_html($s).'</label>';
 submit_button('Register application');echo '</form><h2>Registered sites</h2>';
 foreach(get_option('sv_clients',[]) as $id=>$app){echo '<form method="post"><strong>'.esc_html($app['name']).'</strong> <code>'.esc_html($id).'</code> — '.esc_html($app['redirect_uri']);wp_nonce_field('sv_clients');echo '<button name="disable" value="'.esc_attr($id).'">Disable application</button></form>';}
 echo '</div>';
 }
 static function authorize(){
 nocache_headers();if(!is_ssl())wp_die('HTTPS is required. Configure WordPress HTTPS detection when behind a proxy.');
 $get=fn($k)=>sanitize_text_field(wp_unslash($_REQUEST[$k]??''));$id=$get('client_id');$client=self::client($id);$redirect=wp_unslash($_REQUEST['redirect_uri']??'');$state=$get('state');$challenge=$get('code_challenge');
 $requested=array_values(array_unique(explode(' ',trim($get('scope')))));
 if(!$client||$redirect!==$client['redirect_uri']||!preg_match('/^[a-zA-Z0-9_-]{32,128}$/',$state)||!preg_match('/^[a-zA-Z0-9_-]{43}$/',$challenge)||$get('code_challenge_method')!=='S256'||$get('response_type')!=='code'||!in_array('identity',$requested,true)||array_diff($requested,$client['allowed']))wp_die('Invalid authorization request.');
 if(!is_user_logged_in())auth_redirect();
 if($_SERVER['REQUEST_METHOD']==='POST'){
 check_admin_referer('sv_consent_'.self::hash($id.$state.$challenge));
 if(isset($_POST['deny'])){wp_redirect(add_query_arg(['error'=>'access_denied','state'=>$state],$redirect));exit;}
 $scopes=array_values(array_intersect($requested,array_map('sanitize_text_field',(array)($_POST['scopes']??[]))));$scopes=array_values(array_unique(array_merge(['identity'],$scopes)));$days=(int)($_POST['days']??7);if(!in_array($days,[7,30,90],true))wp_die('Invalid duration.');
 global $wpdb;$grant=self::random();$code=self::random();
 $ok=$wpdb->insert(self::table('grants'),['id'=>$grant,'user_id'=>get_current_user_id(),'client_id'=>$id,'scopes'=>wp_json_encode($scopes),'expires'=>time()+$days*DAY_IN_SECONDS,'revoked'=>0]);if(!$ok)wp_die('Unable to save consent.');
 $ok=$wpdb->insert(self::table('codes'),['id'=>self::hash($code),'user_id'=>get_current_user_id(),'client_id'=>$id,'redirect_uri'=>$redirect,'challenge'=>$challenge,'grant_id'=>$grant,'expires'=>time()+120]);if(!$ok)wp_die('Unable to issue authorization code.');
 $replaced=$wpdb->query($wpdb->prepare('UPDATE '.self::table('grants').' SET revoked=1 WHERE user_id=%d AND client_id=%s AND id<>%s',get_current_user_id(),$id,$grant));if($replaced===false){$wpdb->update(self::table('grants'),['revoked'=>1],['id'=>$grant]);wp_die('Unable to replace previous consent. Please retry.');}
 self::event(get_current_user_id(),$id,'consent_approved',implode(' ',$scopes));wp_redirect(add_query_arg(['code'=>$code,'state'=>$state],$redirect));exit;
 }
 echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Approve data access</title></head><body style="font:16px/1.6 system-ui;background:#eef3fa"><main style="max-width:600px;margin:40px auto;background:white;padding:30px;border-radius:14px"><h1>'.esc_html($client['name']).' wants to connect</h1><p>'.esc_html($client['purpose']).'</p><p>Signed in as '.esc_html(wp_get_current_user()->display_name).'. Your password stays on this data site.</p><form method="post">';
 foreach(['client_id','redirect_uri','state','code_challenge','code_challenge_method','response_type','scope'] as $k)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr(wp_unslash($_REQUEST[$k]??'')).'">';
 wp_nonce_field('sv_consent_'.self::hash($id.$state.$challenge));echo '<p>Identity links your account. Select any additional permissions you approve:</p>';
 foreach($requested as $s)if($s!=='identity')echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="'.esc_attr($s).'"> '.esc_html(str_replace(['read:','write:'],['Read ','Collect / update '],$s)).'</label>';
 echo '<p><label>Permission duration <select name="days"><option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option></select></label></p><p>Revoke future access from your vault. Revocation cannot erase copies already received.</p><button name="approve">Approve selected permissions</button> <button name="deny">Decline</button></form></main></body></html>';exit;
 }
 static function routes(){
 register_rest_route(self::NS,'/health',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>fn()=>['service'=>'sovereign-host','version'=>'0.1.0']]);
 register_rest_route(self::NS,'/token',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>[__CLASS__,'token']]);
 foreach(['userinfo'=>'GET','data'=>['GET','POST'],'revoke'=>'POST'] as $path=>$methods)register_rest_route(self::NS,'/'.$path,['methods'=>$methods,'permission_callback'=>[__CLASS__,'authenticate'],'callback'=>[__CLASS__,$path]]);
 add_filter('rest_post_dispatch',function($response,$server,$request){if(str_starts_with($request->get_route(),'/'.self::NS.'/')){$response->header('Cache-Control','no-store');$response->header('Pragma','no-cache');}return $response;},10,3);
 }
 static function token($r){
 if(!is_ssl())return self::err('HTTPS required.',403);
 $client=self::client((string)$r['client_id']);if(!$client||!hash_equals($client['secret_hash'],self::hash((string)$r['client_secret'])))return self::err('Invalid client credentials.',401);
 if($r['grant_type']!=='authorization_code')return self::err('Unsupported grant type.');
 global $wpdb;$code=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('codes').' WHERE id=%s',self::hash((string)$r['code'])),ARRAY_A);
 $verifier=(string)$r['code_verifier'];$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');
 if(!$code||$code['expires']<time()||$code['client_id']!==$r['client_id']||$code['redirect_uri']!==$r['redirect_uri']||!preg_match('/^[a-zA-Z0-9._~-]{43,128}$/',$verifier)||!hash_equals($code['challenge'],$challenge))return self::err('Invalid or expired authorization code.',401);
 $grant=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('grants').' WHERE id=%s',$code['grant_id']),ARRAY_A);if(!$grant||$grant['revoked']||$grant['expires']<=time())return self::err('Consent is unavailable.',403);
 if($wpdb->delete(self::table('codes'),['id'=>$code['id']])!==1)return self::err('Authorization code was already used.',401);
 $raw=self::random();$expires=min(time()+7*DAY_IN_SECONDS,(int)$grant['expires']);$ok=$wpdb->insert(self::table('tokens'),['id'=>self::hash($raw),'grant_id'=>$grant['id'],'client_id'=>$code['client_id'],'expires'=>$expires]);if(!$ok)return self::err('Unable to issue token.',503);
 self::event($grant['user_id'],$code['client_id'],'sign_in','Connector authorized');return ['access_token'=>$raw,'token_type'=>'Bearer','expires_in'=>$expires-time(),'scope'=>implode(' ',json_decode($grant['scopes'],true))];
 }
 static function authenticate($r){
 if(!is_ssl())return self::err('HTTPS required.',403);$auth=$r->get_header('authorization');if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$auth,$m))return self::err('Bearer token required.',401);
 global $wpdb;$grant=$wpdb->get_row($wpdb->prepare('SELECT g.*, t.expires AS token_expires FROM '.self::table('tokens').' t JOIN '.self::table('grants').' g ON t.grant_id=g.id WHERE t.id=%s',self::hash($m[1])),ARRAY_A);
 if(!$grant||$grant['revoked']||$grant['expires']<=time()||$grant['token_expires']<=time()||!self::client($grant['client_id'])||!get_userdata($grant['user_id']))return self::err('Expired or revoked access.',401);
 $r->set_param('_sv_grant',$grant);return true;
 }
 static function subject($uid){return hash_hmac('sha256',(string)$uid,wp_salt('auth'));}
 static function userinfo($r){$g=$r->get_param('_sv_grant');$u=get_userdata($g['user_id']);return ['issuer'=>home_url('/'),'sub'=>self::subject($g['user_id']),'name'=>$u->display_name];}
 static function vault($uid){global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table('vaults').' WHERE user_id=%d',$uid),ARRAY_A);return $row?['data'=>json_decode($row['payload'],true),'revision'=>(int)$row['revision']]:['data'=>[],'revision'=>0];}
 static function write($uid,$data,$revision){
 global $wpdb;$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.self::table('vaults').' (user_id,payload,revision) VALUES (%d,%s,0)',$uid,'{}'));
 $updated=$wpdb->query($wpdb->prepare('UPDATE '.self::table('vaults').' SET payload=%s,revision=revision+1 WHERE user_id=%d AND revision=%d',wp_json_encode((object)$data),$uid,$revision));
 return $updated===1?true:self::err('Data changed elsewhere. Fetch again before writing.',409);
 }
 static function data($r){
 $g=$r->get_param('_sv_grant');$scopes=json_decode($g['scopes'],true);$v=self::vault($g['user_id']);
 if($r->get_method()==='GET'){
 $fields=$r->get_param('fields');$fields=$fields?explode(',',(string)$fields):array_values(array_filter(self::fields(),fn($f)=>in_array('read:'.$f,$scopes,true)));
 if(!$fields){$can_write=(bool)array_filter($scopes,fn($s)=>str_starts_with($s,'write:'));return $can_write?['data'=>(object)[],'revision'=>$v['revision']]:self::err('No readable categories selected.',403);}
 if(array_diff($fields,self::fields()))return self::err('Unknown categories.',400);
 foreach($fields as $f)if(!in_array('read:'.$f,$scopes,true))return self::err('Read permission denied for '.$f,403);
 $data=[];foreach($fields as $f)$data[$f]=$v['data'][$f]??'';self::event($g['user_id'],$g['client_id'],'read',implode(',',$fields));return ['data'=>(object)$data,'revision'=>$v['revision']];
 }
 $body=$r->get_json_params();$data=$body['data']??null;if(!is_array($data)||!count($data)||!isset($body['revision'])||!is_int($body['revision'])||$body['revision']<0||array_diff(array_keys($data),self::fields()))return self::err('Send valid data categories and an integer revision.');
 foreach($data as $f=>$value){if(!in_array('write:'.$f,$scopes,true))return self::err('Collection permission denied for '.$f,403);if(!is_string($value)||strlen($value)>2000)return self::err('Each value must be text, at most 2000 bytes.');$data[$f]=sanitize_textarea_field($value);}
 $ok=self::write($g['user_id'],array_merge($v['data'],$data),$body['revision']);if(is_wp_error($ok))return $ok;self::event($g['user_id'],$g['client_id'],'collected',implode(',',array_keys($data)));return ['updated'=>array_keys($data),'revision'=>$body['revision']+1];
 }
 static function revoke($r){global $wpdb;$g=$r->get_param('_sv_grant');$ok=$wpdb->update(self::table('grants'),['revoked'=>1],['id'=>$g['id']]);if($ok===false)return self::err('Unable to revoke. Retry on the data site.',503);self::event($g['user_id'],$g['client_id'],'revoked','User disconnected');return ['revoked'=>true];}
 static function dashboard(){
 if(!is_user_logged_in())return '<p><a href="'.esc_url(wp_login_url(get_permalink())).'">Sign in to your data vault</a></p>';
 nocache_headers();global $wpdb;$uid=get_current_user_id();$v=self::vault($uid);ob_start();echo '<section class="sv-vault"><h2>Your data, your terms</h2><p>Data is private until you approve a connected site. Fields are optional.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_vault');echo '<input type="hidden" name="action" value="sv_vault"><input type="hidden" name="revision" value="'.$v['revision'].'">';foreach(self::fields() as $f)echo '<label style="display:block;margin:15px 0">'.esc_html(ucfirst($f)).'<textarea style="display:block;width:100%" name="data['.esc_attr($f).']" maxlength="500">'.esc_textarea($v['data'][$f]??'').'</textarea></label>';echo '<button>Save my data</button></form><h3>Connected sites</h3>';
 $grants=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('grants').' WHERE user_id=%d ORDER BY expires DESC LIMIT 100',$uid),ARRAY_A);if(!$grants)echo '<p>No sites approved yet.</p>';
 foreach($grants as $g){$app=self::client($g['client_id']);$active=!$g['revoked']&&$g['expires']>time()&&$app;echo '<article style="padding:15px;border:1px solid #ddd;margin:10px 0"><strong>'.esc_html($app['name']??'Disabled application').'</strong><p>'.esc_html(implode(', ',json_decode($g['scopes'],true))).'</p><p>'.($active?'Active until '.esc_html(wp_date('Y-m-d H:i',(int)$g['expires'])):'Revoked, expired, or disabled').'</p>';if($active){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_revoke');echo '<input type="hidden" name="action" value="sv_revoke"><input type="hidden" name="grant" value="'.esc_attr($g['id']).'"><button>Revoke access</button></form>';}echo '</article>';}
 echo '<h3>Activity (last 90 days)</h3><ul>';foreach($wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('events').' WHERE user_id=%d ORDER BY id DESC LIMIT 50',$uid),ARRAY_A) as $e){$app=self::client($e['client_id']);echo '<li>'.esc_html(wp_date('Y-m-d H:i',(int)$e['created']).' · '.($app['name']??'Your vault').' · '.$e['event'].' · '.$e['detail']).'</li>';}echo '</ul><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('sv_export');echo '<input type="hidden" name="action" value="sv_export"><button>Export my data and permissions</button></form><p>Revoking stops future access. It does not erase copies a site already received.</p></section>';return ob_get_clean();
 }
 static function vault_save(){if(!is_user_logged_in())wp_die('Sign in required.');check_admin_referer('sv_vault');$data=[];foreach(self::fields() as $f){$s=wp_unslash($_POST['data'][$f]??'');if(!is_string($s)||strlen($s)>2000)wp_die('Invalid data value.');$data[$f]=sanitize_textarea_field($s);}$ok=self::write(get_current_user_id(),$data,absint($_POST['revision']??0));if(is_wp_error($ok))wp_die(esc_html($ok->get_error_message()));self::event(get_current_user_id(),'','vault_updated','Manual update');wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;}
 static function revoke_form(){if(!is_user_logged_in())wp_die('Sign in required.');check_admin_referer('sv_revoke');global $wpdb;$id=sanitize_text_field(wp_unslash($_POST['grant']??''));$wpdb->update(self::table('grants'),['revoked'=>1],['id'=>$id,'user_id'=>get_current_user_id()]);self::event(get_current_user_id(),'','revoked','Grant '.$id);wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;}
 static function export(){if(!is_user_logged_in())wp_die('Sign in required.');check_admin_referer('sv_export');global $wpdb;$uid=get_current_user_id();nocache_headers();header('Content-Type: application/json');header('Content-Disposition: attachment; filename="sovereign-data.json"');echo wp_json_encode(['vault'=>self::vault($uid),'grants'=>$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table('grants').' WHERE user_id=%d',$uid),ARRAY_A),'events'=>$wpdb->get_results($wpdb->prepare('SELECT client_id,event,detail,created FROM '.self::table('events').' WHERE user_id=%d ORDER BY id DESC',$uid),ARRAY_A)],JSON_PRETTY_PRINT);exit;}
}
Sovereign_Host::boot();
