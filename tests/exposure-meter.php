<?php
// Checks Exposure Meter without a WordPress install: the functions WordPress would provide
// are replaced by simple stand-ins below. Run with: php tests/exposure-meter.php
// A minimal stand-in for WordPress's table helper, which only records what it is given.
$fake=sys_get_temp_dir().'/em-test-wp-'.getmypid().'/'; @mkdir($fake.'wp-admin/includes',0777,true);
file_put_contents($fake.'wp-admin/includes/upgrade.php','<?php function dbDelta($q){$GLOBALS["dbdelta"][]=$q;}');
define('ABSPATH',$fake); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400); define('ARRAY_A','ARRAY_A');
$GLOBALS['opt']=['em_db_version'=>'1.3.0','em_anonymize_ip'=>true,'em_exclude_roles'=>['administrator']];
$GLOBALS['tr']=[]; $GLOBALS['requests']=[]; $GLOBALS['answer']='{"status":"success","countryCode":"DE"}';
$GLOBALS['filters']=[]; $GLOBALS['singular']=true; $GLOBALS['tz']=new DateTimeZone('Europe/Berlin');
function add_action(){} function add_filter($h){$GLOBALS['filters'][]=$h;} function register_activation_hook(){} function register_deactivation_hook(){}
function is_404(){return false;} function is_preview(){return false;} function is_robots(){return false;} function is_trackback(){return false;}
function is_singular(){return $GLOBALS['singular'];}
function wp_date($f,$ts=null){return (new DateTimeImmutable('@'.($ts??time())))->setTimezone($GLOBALS['tz'])->format($f);}
function wp_json_encode($x){return json_encode($x);} function rest_url($p){return 'https://example.test/wp-json/'.$p;}
function get_post($id){return $id==42?(object)['ID'=>42]:null;} function get_post_status($id){return $id==42?'publish':false;}
class WP_REST_Response{public $data;function __construct($d){$this->data=$d;}}
class WP_REST_Request{private $p;function __construct($p){$this->p=$p;}function get_param($k){return $this->p[$k]??null;}}
function plugin_dir_path($f){return dirname($f).'/';} function plugin_dir_url($f){return 'https://example.test/wp-content/plugins/exposure-meter/';}
function get_option($k,$d=false){return $GLOBALS['opt'][$k]??$d;} function update_option($k,$v){$GLOBALS['opt'][$k]=$v;} function delete_option($k){unset($GLOBALS['opt'][$k]);}
function get_transient($k){return $GLOBALS['tr'][$k]??false;} function set_transient($k,$v,$t=0){$GLOBALS['tr'][$k]=$v;}
function wp_salt($s='auth'){return 'test-salt-not-secret';}
function wp_clear_scheduled_hook($h){$GLOBALS["cleared"][]=$h;}
function wp_remote_get($url,$a=[]){$GLOBALS['requests'][]=$url;return ['body'=>$GLOBALS['answer']];}
function is_wp_error($x){return false;} function wp_remote_retrieve_body($r){return $r['body'];}
function sanitize_text_field($s){return trim(strip_tags((string)$s));} function wp_unslash($s){return $s;} function sanitize_url($s){return (string)$s;}
function is_feed(){return false;} function is_search(){return false;} function is_admin(){return false;} function is_user_logged_in(){return false;}
function get_queried_object_id(){return 42;} function get_the_title($id=0){return 'A page';} function get_bloginfo($k=''){return 'Site';}
function current_time($t){return wp_date('Y-m-d H:i:s');} function home_url($p=''){return 'https://example.test'.$p;}
class FakeDB { public $prefix='wp_'; public $options='wp_options'; public $inserts=[]; public $replaces=[]; public $queries=[]; public $hasColumn=true; public $recent=false;
  function insert($t,$d){$this->inserts[]=[$t,$d];} function replace($t,$d){$this->replaces[]=[$t,$d];}
  function prepare($q,...$a){return vsprintf(str_replace('%s',"'%s'",$q),$a);} function esc_like($s){return addcslashes($s,'_%\\');}
  function hide_errors(){return true;} function show_errors(){} function get_charset_collate(){return "DEFAULT CHARSET=utf8mb4";}
  function query($q){$this->queries[]=$q; if(strpos($q,'DROP COLUMN ip_anon')!==false)$this->hasColumn=false; return 1;}
  function get_var($q){ if(strpos($q,'SHOW TABLES')!==false)return 'wp_em_visits'; if(strpos($q,'SHOW COLUMNS')!==false)return $this->hasColumn?'ip_anon':null; if(strpos($q,'SELECT 1 FROM')!==false)return $this->recent?1:null; return 0; } }
$wpdb=new FakeDB;
require __DIR__.'/../exposure-meter.php';

$fail=0; function check($label,$ok){ global $fail; if(!$ok)$fail++; echo ($ok?'PASS':'FAIL')."  $label\n"; }

// 1. shortening
check('IPv4: last number set to 0',              em_short_ip('203.0.113.77')==='203.0.113.0');
check('IPv6: only the first 48 bits kept',       em_short_ip('2a02:8109:1a2b:3c4d:5e6f:7a8b:9c0d:1e2f')==='2a02:8109:1a2b::');
check('IPv4 written the IPv6 way',               em_short_ip('::ffff:203.0.113.77')==='203.0.113.0');
check('not an address gives nothing',            em_short_ip('nonsense')==='' && em_short_ip('')==='');

// 2. country lookup: only the shortened address leaves the server, nothing reversible is kept
$ip='203.0.113.77';
$r=em_geo_lookup($ip);
check('lookup returns the country, no city',     $r===['country_code'=>'DE','city'=>'']);
check('exactly one request, with the shortened address', count($GLOBALS['requests'])===1 && strpos($GLOBALS['requests'][0],'/203.0.113.0?')!==false && strpos($GLOBALS['requests'][0],'113.77')===false);
check('city is no longer requested',             strpos($GLOBALS['requests'][0],'city')===false);
$keys=implode(' ',array_keys($GLOBALS['tr']));
check('cache name is not made from the address in a reversible way', strpos($keys,md5($ip))===false && strpos($keys,md5('203.0.113.0'))===false && strpos($keys,'203')===false && preg_match('/^em_geo_[0-9a-f]{32}$/',$keys)===1);
em_geo_lookup('203.0.113.5');
check('a neighbour in the same network reuses the cached answer', count($GLOBALS['requests'])===1);
em_geo_lookup('192.168.1.20'); em_geo_lookup('127.0.0.1'); em_geo_lookup('0.0.0.0'); em_geo_lookup('10.1.2.3');
check('private and local addresses are never looked up', count($GLOBALS['requests'])===1);
$GLOBALS['answer']='{"status":"success","countryCode":"<b>x"}'; $x=em_geo_lookup('198.51.100.9');
check('a malformed country code is discarded',   $x['country_code']==='');
$GLOBALS['answer']='{"status":"success","countryCode":"DE"}';

// 3. recording a visit: no address anywhere in what is written
foreach(['203.0.113.77','2a02:8109:1a2b:3c4d:5e6f:7a8b:9c0d:1e2f'] as $addr){
  $wpdb->inserts=$wpdb->replaces=[]; $_SERVER=['REQUEST_URI'=>'/release/some-album/?utm_source=x','REMOTE_ADDR'=>$addr,'HTTP_USER_AGENT'=>'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15','HTTP_REFERER'=>'https://www.google.com/']; $_GET=[];
  em_record_visit();
  $written=json_encode([$wpdb->inserts,$wpdb->replaces]);
  $row=$wpdb->inserts[0][1]??[];
  check("visit from $addr is recorded", count($wpdb->inserts)===1 && $row['post_id']===42 && $row['country_code']==='DE' && strlen($row['visitor_hash'])===64);
  check('  no IP column, no address, shortened or not, in the written data', !array_key_exists('ip_anon',$row) && strpos($written,$addr)===false && strpos($written,em_short_ip($addr))===false && strpos($written,'203.0')===false && strpos($written,'2a02')===false);
}
$_SERVER['REMOTE_ADDR']='203.0.113.77'; $wpdb->inserts=[]; em_record_visit(); $h1=$wpdb->inserts[0][1]['visitor_hash'];
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (Windows NT 10.0) Firefox/130.0'; $wpdb->inserts=[]; em_record_visit(); $h2=$wpdb->inserts[0][1]['visitor_hash'];
check('different browsers still count as different visitors', $h1!==$h2);

// 3b. which page a visit belongs to (1.4.0)
$wpdb=new FakeDB;
$visit=function($uri,$singular=true){ global $wpdb; $GLOBALS['singular']=$singular; $wpdb->inserts=[]; $_SERVER=['REQUEST_URI'=>$uri,'REMOTE_ADDR'=>'203.0.113.9','HTTP_USER_AGENT'=>'Mozilla/5.0 (Windows NT 10.0) Firefox/131.0']; $_GET=[]; em_record_visit(); return $wpdb->inserts[0][1]??null; };
$r=$visit('/category/releases/?page=2',false);
check('archive page: no post id (a category id is not a post), its address is kept', $r['post_id']===0 && $r['page_path']==='/category/releases/');
$r=$visit('/release/some-album/');
check('single page: its post id and address', $r['post_id']===42 && $r['page_path']==='/release/some-album/');
$r=$visit('/%D9%87%D9%86%D8%B1%D9%85%D9%86%D8%AF%D8%A7%D9%86/',false);
check('Persian address is stored readable', $r['page_path']==='/هنرمندان/');
$r=$visit('//double//slashes/',false);
check('address is tidied', $r['page_path']==='/double/slashes/');
$GLOBALS['opt']['em_cache_mode']=true; $r=$visit('/release/some-album/');
check('with cache-compatible mode on, the page itself does not count (the script does)', $r===null);
// the script's endpoint
$wpdb->inserts=[]; $_SERVER=['REMOTE_ADDR'=>'203.0.113.9','HTTP_USER_AGENT'=>'Mozilla/5.0 (Windows NT 10.0) Firefox/131.0'];
$res=em_rest_ping(new WP_REST_Request(['post_id'=>'42','path'=>'/release/some-album/','referrer'=>'https://www.instagram.com/','query'=>'?utm_campaign=rio-ep&utm_source=instagram']));
$row=$wpdb->inserts[0][1]??[];
check('script report is counted with page, source and campaign', $res->data['status']==='ok' && $row['post_id']===42 && $row['referrer_type']==='social' && $row['utm_campaign']==='rio-ep');
$wpdb->inserts=[]; $res=em_rest_ping(new WP_REST_Request(['post_id'=>'999','path'=>'/x/']));
check('an unknown post id from the script is not trusted', $res->data['status']==='ok' && $wpdb->inserts[0][1]['post_id']===0);
$wpdb->recent=true; $wpdb->inserts=[]; $res=em_rest_ping(new WP_REST_Request(['post_id'=>'42','path'=>'/release/some-album/']));
check('the same visitor and page within 30 seconds counts once', $res->data['status']==='repeat' && !$wpdb->inserts);
$wpdb->recent=false; $GLOBALS['opt']['em_cache_mode']=false; $res=em_rest_ping(new WP_REST_Request(['post_id'=>'42','path'=>'/']));
check('with the mode off, script reports are ignored', $res->data['status']==='off');
$_SERVER['HTTP_USER_AGENT']='curl/8.5'; $GLOBALS['opt']['em_cache_mode']=true; $res=em_rest_ping(new WP_REST_Request(['path'=>'/']));
check('bots are ignored', $res->data['status']==='bot'); $GLOBALS['opt']['em_cache_mode']=false;

// 3c. time (1.4.0): everything in the site's own time zone
check('next day across the clock change in October', em_to('2026-10-25')==='2026-10-26 00:00:00' && em_to('2026-12-31')==='2027-01-01 00:00:00');
check('day check rejects nonsense', em_valid_day('2026-10-12') && !em_valid_day('2026-02-30') && !em_valid_day("2026-10-12' OR 1") && !em_valid_day(''));
$berlin=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')));
check('"5 minutes ago" is in the site\'s time, not UTC', abs(strtotime(em_ago(300))-strtotime($berlin->format('Y-m-d H:i:s'))+300)<=2);
check('"today" is the site\'s date', em_today()===$berlin->format('Y-m-d'));
check('no query uses the database clock or a date function on the column any more', !preg_match('/CURDATE|NOW\(\)|DATE\(created_at\)\s*(BETWEEN|=|>=)/',$src0=file_get_contents(__DIR__.'/../exposure-meter.php')));
check('the 5-minute schedule is known before anything is scheduled', in_array('cron_schedules',$GLOBALS['filters'],true));
check('dashboard loads nothing from other servers', !preg_match('#fonts\.googleapis|cdn\.jsdelivr|unpkg\.com#',$src0)
  && is_file(__DIR__.'/../assets/chart.umd.min.js') && is_file(__DIR__.'/../assets/fonts/fonts.css'));
preg_match_all('/url\(([^)]+)\)/',file_get_contents(__DIR__.'/../assets/fonts/fonts.css'),$fu);
check('every font the stylesheet names is there', count($fu[1])===6 && !array_filter($fu[1],fn($f)=>!is_file(__DIR__.'/../assets/fonts/'.$f)));

// 4. upgrade from 1.3.0: stored addresses are removed
em_maybe_upgrade(); $q=implode("\n",$wpdb->queries);
check('upgrade removes the IP column',            strpos($q,'ALTER TABLE wp_em_visits DROP COLUMN ip_anon')!==false);
check('upgrade removes old cached lookups',       strpos($q,"DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_em\\_geo\\_%'")!==false);
check('upgrade removes the old setting and records the version', !isset($GLOBALS['opt']['em_anonymize_ip']) && $GLOBALS['opt']['em_db_version']===EM_VERSION);
$n=count($wpdb->queries); em_maybe_upgrade();
check('upgrade (1.4.0) adds the page address column and the per-page index', strpos(implode("\n",$GLOBALS['dbdelta']??[]),'page_path varchar(255)')!==false && strpos(implode("\n",$GLOBALS['dbdelta']),'KEY post_created (post_id,created_at)')!==false);
check('upgrade runs only once',                   count($wpdb->queries)===$n);
// a server that refuses ALTER TABLE: the column stays, so it must be emptied
$GLOBALS['opt']['em_db_version']='1.3.0';
$wpdb=new class extends FakeDB { function query($q){$this->queries[]=$q; return false;} };
em_maybe_upgrade();
check('if the column cannot be removed it is emptied', strpos(implode("\n",$wpdb->queries),"UPDATE wp_em_visits SET ip_anon=''")!==false);

// 5. version and map file
$src=file_get_contents(__DIR__.'/../exposure-meter.php');
preg_match('/^ \* Version:\s+(\S+)/m',$src,$m);
check('version header and constant agree ('.EM_VERSION.')', ($m[1]??'')===EM_VERSION);
$map=em_world_map(); $codes=array_values(array_filter(array_column($map['countries']??[],'c')));
check('world map loads: '.count($map['countries']??[]).' shapes', is_array($map) && $map['w']===1000 && $map['h']>300 && count($codes)>200);
check('  every country code appears once',        count($codes)===count(array_unique($codes)));
check('  every entry has a shape or a dot',       !array_filter($map['countries'],fn($c)=>empty($c['d'])&&empty($c['m'])));
check('  shapes stay inside the map',             !array_filter($map['countries'],function($c)use($map){ preg_match_all('/(-?[\d.]+) (-?[\d.]+)/',$c['d'],$p); return $p[1] && (min($p[1])<-1||max($p[1])>$map['w']+1||min($p[2])<-1||max($p[2])>$map['h']+1); }));
check('  no shape spans the whole map width (the old smear across the map)', !array_filter($map['countries'],function($c){ foreach(explode('M',$c['d']) as $ring){ preg_match_all('/(-?[\d.]+) -?[\d.]+/',$ring,$p); if($p[1] && max($p[1])-min($p[1])>700)return true; } return false; }));
foreach(['DE','US','IR','NZ','AE','MY','SG','MT','HK','XK','TW'] as $c) if(!in_array($c,$codes,true)){ check("  $c is on the map",false); }
check('  countries the old map could not light are present (NZ AE MY SG MT HK XK TW)', !array_diff(['NZ','AE','MY','SG','MT','HK','XK','TW'],$codes));
check('country names fall back to the map (TW, HK, XK)', em_country_name('TW')==='Taiwan' && em_country_name('hk')==='Hong Kong' && em_country_name('XK')==='Kosovo' && em_country_name('DE')==='Germany' && em_country_name('??')==='??');
check('page no longer downloads map data or code from elsewhere', strpos($src,'world-atlas')===false && strpos($src,'topojson')===false);
echo $fail? "\n$fail FAILED\n" : "\nall passed\n"; exit($fail?1:0);
