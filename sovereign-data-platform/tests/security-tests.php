<?php
// Contract tests: WordPress doubles plus real SQLite. Not a live WordPress integration test.
define('ABSPATH','/wp/');define('ARRAY_A','ARRAY_A');define('DAY_IN_SECONDS',86400);
function do_action(...$a){} function add_action(...$a){} function add_filter(...$a){} function add_shortcode(...$a){} function register_activation_hook(...$a){} function register_deactivation_hook(...$a){} function wp_next_scheduled(...$a){return true;}
function is_ssl(){return $GLOBALS['ssl']??true;} function wp_json_encode($a,$flags=0){return json_encode($a,$flags);}function wp_salt($s){return 'test-only-salt-'.$s;}function home_url($p=''){return 'https://data.test/'.ltrim($p,'/');}function get_userdata($id){return $id>0?(object)['ID'=>$id,'display_name'=>'User '.$id]:false;}
function sanitize_textarea_field($s){return strip_tags($s);}function sanitize_text_field($s){return strip_tags((string)$s);}function get_option($k,$default=false){return $GLOBALS['options'][$k]??$default;}function is_wp_error($e){return $e instanceof WP_Error;}
class WP_Error{public function __construct(public $code,public $message,public $data=[]){ }function get_error_message(){return $this->message;}function get_error_code(){return $this->code;}function get_error_data(){return $this->data;}}
class WP_REST_Response{public $headers=[];function __construct(public $data,public $status=200){}function header($k,$v){$this->headers[$k]=$v;}}
function absint($n){return abs((int)$n);} function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function current_time($s){return gmdate('Y-m-d H:i:s');}
function get_post_meta($id,$key,$single=true){return $GLOBALS['postmeta'][$id][$key]??'';}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}function wp_safe_remote_request($u,$a){$GLOBALS['http_last']=[$u,$a];return $GLOBALS['http_response']??['status'=>200,'body'=>'{"ok":true}'];}function wp_remote_retrieve_response_code($r){return $r['status'];}function wp_remote_retrieve_body($r){return $r['body'];}function add_query_arg($k,$v,$u){return $u.'?'.urlencode($k).'='.urlencode($v);}
class TestRequest implements ArrayAccess {
 public function __construct(public $params=[],public $method='POST',public $headers=[],public $json=null,public $route=''){}
 function offsetExists($k):bool{return isset($this->params[$k]);}function offsetGet($k):mixed{return $this->params[$k]??null;}function offsetSet($k,$v):void{$this->params[$k]=$v;}function offsetUnset($k):void{unset($this->params[$k]);}function get_header($k){return $this->headers[$k]??'';}function set_param($k,$v){$this->params[$k]=$v;}function get_param($k){return $this->params[$k]??null;}function get_method(){return $this->method;}function get_json_params(){return $this->json;}function get_body_params(){return $this->params;}function get_route(){return $this->route;}
}
class TestDB{
 public $prefix='wp_';public $pdo;
 function __construct(){$this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);foreach([
 'codes'=>'id TEXT PRIMARY KEY,user_id INTEGER,client_id TEXT,redirect_uri TEXT,challenge TEXT,grant_id TEXT,expires INTEGER',
 'grants'=>'id TEXT PRIMARY KEY,user_id INTEGER,client_id TEXT,scopes TEXT,expires INTEGER,revoked INTEGER DEFAULT 0',
 'tokens'=>'id TEXT PRIMARY KEY,grant_id TEXT,client_id TEXT,expires INTEGER',
 'vaults'=>'user_id INTEGER PRIMARY KEY,payload TEXT,revision INTEGER DEFAULT 0',
 'events'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,client_id TEXT,event TEXT,detail TEXT,created INTEGER'
 ] as $n=>$s)$this->pdo->exec('CREATE TABLE wp_sv_'.$n.' ('.$s.')');}
 function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){return $m[0]==='%d'?(string)(int)$args[$i++]:$this->pdo->quote((string)$args[$i++]);},$sql);}
 function query($s){return $this->pdo->exec(str_replace('INSERT IGNORE','INSERT OR IGNORE',$s));}
 function get_row($s,$m=null){return $this->pdo->query($s)->fetch(PDO::FETCH_ASSOC)?:null;}
 function get_col($s,$m=null){return $GLOBALS['log_columns'];}
 function get_results($s,$m=null){return $this->pdo->query($s)->fetchAll(PDO::FETCH_ASSOC);}
 function insert($t,$d){$s=$this->pdo->prepare('INSERT INTO '.$t.' ('.implode(',',array_keys($d)).') VALUES ('.implode(',',array_fill(0,count($d),'?')).')');$s->execute(array_values($d));return $s->rowCount();}
 function delete($t,$w){$a=[];foreach($w as $k=>$v)$a[]=$k.'=?';$s=$this->pdo->prepare('DELETE FROM '.$t.' WHERE '.implode(' AND ',$a));$s->execute(array_values($w));return $s->rowCount();}
 function update($t,$d,$w){$a=[];$b=[];foreach($d as $k=>$v)$a[]=$k.'=?';foreach($w as $k=>$v)$b[]=$k.'=?';$s=$this->pdo->prepare('UPDATE '.$t.' SET '.implode(',',$a).' WHERE '.implode(' AND ',$b));$s->execute(array_merge(array_values($d),array_values($w)));return $s->rowCount();}
}
$wpdb=new TestDB();$checks=0;function check($v,$n){global $checks;if(!$v)throw new Exception('FAIL: '.$n);$checks++;echo "PASS: $n\n";}function denied($v,$s){return is_wp_error($v)&&($v->data['status']??0)===$s;}
require __DIR__.'/../plugins/sovereign-data-host/sovereign-data-host.php';require __DIR__.'/../plugins/sovereign-wp-connector/sovereign-wp-connector.php';
$id='sv_'.str_repeat('a',24);$secret=str_repeat('b',64);$callback='https://client.test/wp-admin/admin-post.php?action=sv_callback';$options=['sv_clients'=>[$id=>['name'=>'Test app','purpose'=>'Consent','redirect_uri'=>$callback,'secret_hash'=>Sovereign_Host::hash($secret),'allowed'=>Sovereign_Host::scopes()]]];
$grant='grant1';$wpdb->insert('wp_sv_grants',['id'=>$grant,'user_id'=>1,'client_id'=>$id,'scopes'=>json_encode(['identity','read:interests','write:interests']),'expires'=>time()+3600,'revoked'=>0]);
$verifier=str_repeat('c',64);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'=');$code=str_repeat('d',64);$wpdb->insert('wp_sv_codes',['id'=>Sovereign_Host::hash($code),'user_id'=>1,'client_id'=>$id,'redirect_uri'=>$callback,'challenge'=>$challenge,'grant_id'=>$grant,'expires'=>time()+120]);
$input=['client_id'=>$id,'client_secret'=>$secret,'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$callback,'code_verifier'=>$verifier];
check(denied(Sovereign_Host::token(new TestRequest(array_merge($input,['client_secret'=>'bad']))),401),'Wrong secret denied');
check(denied(Sovereign_Host::token(new TestRequest(array_merge($input,['redirect_uri'=>$callback.'/other']))),401),'Exact redirect required');
check(denied(Sovereign_Host::token(new TestRequest(array_merge($input,['code_verifier'=>str_repeat('e',64)]))),401),'Wrong PKCE denied');
$token=Sovereign_Host::token(new TestRequest($input));check(!is_wp_error($token)&&strlen($token['access_token'])===64,'Valid PKCE token exchange');check(denied(Sovereign_Host::token(new TestRequest($input)),401),'Code replay denied');
$raw=$token['access_token'];$row=$wpdb->get_row('SELECT * FROM wp_sv_tokens');check($row['id']!==$raw&&$row['id']===hash('sha256',$raw),'Only token hash stored');
check(Sovereign_Host::write(1,['interests'=>'Gaming','location'=>'Private'],0)===true,'Vault saved');check(denied(Sovereign_Host::write(1,['interests'=>'Old'],0),409),'Stale write denied');
$r=new TestRequest([], 'GET',['authorization'=>'Bearer '.$raw]);check(Sovereign_Host::authenticate($r)===true,'Bearer accepted');$info=Sovereign_Host::userinfo($r);check($info['sub']===Sovereign_Host::subject(1)&&!isset($info['email']),'Stable private subject');
$data=Sovereign_Host::data($r);check((array)$data['data']===['interests'=>'Gaming'],'Approved fields only');
$r->set_param('fields','location');check(denied(Sovereign_Host::data($r),403),'Unapproved read denied');$r->set_param('fields','interests');
$r->method='POST';$r->json=['data'=>['location'=>'New'],'revision'=>1];check(denied(Sovereign_Host::data($r),403),'Unapproved collection denied');
$r->json=['data'=>['interests'=>'<b>Technology</b>'],'revision'=>1];$update=Sovereign_Host::data($r);check(($update['revision']??0)===2&&Sovereign_Host::vault(1)['data']['interests']==='Technology','Approved collection sanitized and saved');
check(Sovereign_Host::vault(2)['data']===[],'User isolation');$r->json=['data'=>['interests'=>'Overwrite'],'revision'=>1];check(denied(Sovereign_Host::data($r),409),'Collection conflict denied');
check(denied(Sovereign_Host::authenticate(new TestRequest([], 'GET')),401),'Missing bearer denied');$ssl=false;check(denied(Sovereign_Host::authenticate($r),403),'HTTP denied');$ssl=true;
$wpdb->update('wp_sv_grants',['expires'=>time()-1],['id'=>$grant]);check(denied(Sovereign_Host::authenticate($r),401),'Expired consent denied');$wpdb->update('wp_sv_grants',['expires'=>time()+3600],['id'=>$grant]);
$wpdb->update('wp_sv_tokens',['expires'=>time()-1],['id'=>hash('sha256',$raw)]);check(denied(Sovereign_Host::authenticate($r),401),'Expired token denied');$wpdb->update('wp_sv_tokens',['expires'=>time()+3600],['id'=>hash('sha256',$raw)]);
$hold=$options['sv_clients'];$options['sv_clients']=[];check(denied(Sovereign_Host::authenticate($r),401),'Disabled app denied');$options['sv_clients']=$hold;
Sovereign_Host::revoke($r);check(denied(Sovereign_Host::authenticate($r),401),'Revocation enforced');check(count($wpdb->get_results('SELECT * FROM wp_sv_events'))>=4,'Access and consent audited');
$e=Sovereign_Connector::encrypt('private-token');check($e!=='private-token'&&Sovereign_Connector::decrypt($e)==='private-token','Encryption round trip');$t=base64_decode($e);$t[strlen($t)-1]=chr(ord($t[strlen($t)-1])^1);check(Sovereign_Connector::decrypt(base64_encode($t))==='','Ciphertext tampering rejected');
check(!Sovereign_Connector::url('http://example.com')&&!Sovereign_Connector::url('https://u:p@example.com')&&Sovereign_Connector::url('https://example.com/wordpress/'),'URL validation');

require __DIR__.'/native-gateway-tests.php';
echo "TOTAL: $checks checks passed\n";
