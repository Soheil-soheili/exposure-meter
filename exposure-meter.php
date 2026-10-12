<?php
/**
 * Plugin Name:       Exposure Meter
 * Plugin URI:        https://soheilsoheili.com
 * Description:       Professional visitor analytics built for record labels.
 * Version:           1.4.0
 * Author:            Soheil Soheili
 * License:           GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * @package ExposureMeter
 */
defined('ABSPATH')||exit;
define('EM_VERSION','1.4.0');
define('EM_FILE',__FILE__);
define('EM_PATH',plugin_dir_path(__FILE__));
define('EM_URL',plugin_dir_url(__FILE__));

// ── Activation ──────────────────────────────────────────────────────────────
register_activation_hook(__FILE__,'em_activate');
function em_activate():void{
    em_create_tables();
    update_option('em_db_version',EM_VERSION);
    update_option('em_activated_at',current_time('mysql'));
    add_option('em_exclude_roles',['administrator','editor']);
    add_option('em_retention_days',365);
    add_option('em_cache_mode',false);
    add_option('em_report_email',get_option('admin_email'));
    add_option('em_report_frequency','em_weekly');
    add_option('em_report_enabled',false);
}
// Table layout in the form WordPress's dbDelta() expects (one column per line), so that
// running it again on an existing site adds what is missing.
function em_create_tables():void{
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $c=$wpdb->get_charset_collate();$p=$wpdb->prefix;
    dbDelta("CREATE TABLE {$p}em_visits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_path varchar(255) NOT NULL DEFAULT '',
  visitor_hash char(64) NOT NULL DEFAULT '',
  country_code char(2) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  device varchar(20) NOT NULL DEFAULT '',
  browser varchar(50) NOT NULL DEFAULT '',
  os varchar(50) NOT NULL DEFAULT '',
  referrer varchar(500) NOT NULL DEFAULT '',
  referrer_type varchar(20) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(100) NOT NULL DEFAULT '',
  utm_term varchar(100) NOT NULL DEFAULT '',
  utm_content varchar(100) NOT NULL DEFAULT '',
  is_new_visitor tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY created_at (created_at),
  KEY visitor_hash (visitor_hash),
  KEY post_created (post_id,created_at)
) $c;");
    dbDelta("CREATE TABLE {$p}em_aggregates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  stat_date date NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  views bigint(20) unsigned NOT NULL DEFAULT 0,
  uniques bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY date_post (stat_date,post_id)
) $c;");
    dbDelta("CREATE TABLE {$p}em_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_name varchar(100) NOT NULL DEFAULT '',
  event_value varchar(255) NOT NULL DEFAULT '',
  visitor_hash char(64) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY event_name (event_name)
) $c;");
    dbDelta("CREATE TABLE {$p}em_online (
  visitor_hash char(64) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_title varchar(255) NOT NULL DEFAULT '',
  country_code char(2) NOT NULL DEFAULT '',
  device varchar(20) NOT NULL DEFAULT '',
  referrer varchar(500) NOT NULL DEFAULT '',
  last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (visitor_hash),
  KEY last_seen (last_seen)
) $c;");
}
register_deactivation_hook(__FILE__,'em_deactivate');
function em_deactivate():void{
    foreach(['em_aggregate_stats','em_purge_old_data','em_purge_online_table','em_scheduled_report'] as $h)wp_clear_scheduled_hook($h);
}

// ── Upgrade ─────────────────────────────────────────────────────────────────
// Runs once after the plugin files were replaced by a newer version.
add_action('plugins_loaded','em_maybe_upgrade',5);
function em_maybe_upgrade():void{
    if(get_option('em_db_version')===EM_VERSION)return;
    global $wpdb;
    $t=$wpdb->prefix.'em_visits';
    // 1.3.5: IP addresses are no longer stored. Remove the ones stored by earlier versions:
    // the column in the visits table, and the cached country lookups, whose names were
    // made from the address in a way that could be reversed.
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))===$t){
        $shown=$wpdb->hide_errors();
        if($wpdb->get_var("SHOW COLUMNS FROM {$t} LIKE 'ip_anon'"))$wpdb->query("ALTER TABLE {$t} DROP COLUMN ip_anon");
        // If the column could not be removed (no permission to change tables), at least empty it.
        if($wpdb->get_var("SHOW COLUMNS FROM {$t} LIKE 'ip_anon'"))$wpdb->query("UPDATE {$t} SET ip_anon='' WHERE ip_anon!=''");
        if($shown)$wpdb->show_errors();
    }
    // 1.4.0: the page path for visits to archives and other pages, an index for the per-page
    // counts, and the background jobs scheduled afresh (earlier versions could not schedule them).
    em_create_tables();
    foreach(['em_purge_online_table','em_scheduled_report'] as $h)wp_clear_scheduled_hook($h);
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_em\\_geo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_em\\_geo\\_%'");
    delete_option('em_anonymize_ip');
    update_option('em_db_version',EM_VERSION);
}

// ── Boot ────────────────────────────────────────────────────────────────────
// Registered before anything is scheduled: an interval WordPress does not know yet cannot be
// scheduled, which is why the 5-minute clean-up and the weekly report never ran before 1.4.0.
add_filter('cron_schedules','em_cron_intervals');
add_action('plugins_loaded','em_boot');
function em_boot():void{
    if(!wp_next_scheduled('em_aggregate_stats'))wp_schedule_event(strtotime('tomorrow 01:00:00'),'daily','em_aggregate_stats');
    if(!wp_next_scheduled('em_purge_old_data'))wp_schedule_event(strtotime('tomorrow 02:00:00'),'daily','em_purge_old_data');
    if(!wp_next_scheduled('em_purge_online_table'))wp_schedule_event(time()+60,'em_five_minutes','em_purge_online_table');
    if(get_option('em_report_enabled')&&!wp_next_scheduled('em_scheduled_report'))em_schedule_report();
    add_action('template_redirect','em_record_visit',5);
    add_action('wp_footer','em_beacon',99);
    add_action('admin_init','em_purge_online_rows');
    add_action('rest_api_init','em_register_rest_routes');
    add_action('admin_menu','em_admin_menu');
    add_action('admin_enqueue_scripts','em_admin_assets');
    add_action('wp_dashboard_setup','em_register_dashboard_widget');
    add_action('add_meta_boxes','em_register_metabox');
    add_action('manage_posts_columns','em_sparkline_column');
    add_action('manage_pages_columns','em_sparkline_column');
    add_action('manage_posts_custom_column','em_sparkline_column_data',10,2);
    add_action('manage_pages_custom_column','em_sparkline_column_data',10,2);
    add_action('em_aggregate_stats','em_aggregate_daily_stats');
    add_action('em_purge_old_data','em_purge_old_data_rows');
    add_action('em_purge_online_table','em_purge_online_rows');
    add_action('em_scheduled_report','em_send_report');
}
function em_cron_intervals(array $s):array{
    $s['em_five_minutes']=['interval'=>300,'display'=>'Every 5 Min (EM)'];
    $s['em_weekly']=['interval'=>604800,'display'=>'Weekly (EM)'];
    return $s;
}

// ── Time ────────────────────────────────────────────────────────────────────
// Visits are stored in the site's own time zone (Settings > General), so every "today",
// "last 7 days" and "online in the last 5 minutes" is worked out in that time zone too.
// Before 1.4.0 some of these used UTC or the database server's clock, which, for example,
// kept visitors "online" for two hours on a site in Berlin.
function em_today():string{return wp_date('Y-m-d');}
function em_days_ago(int $days):string{return wp_date('Y-m-d',time()-$days*DAY_IN_SECONDS);}
function em_from(string $day):string{return $day.' 00:00:00';}
function em_to(string $day):string{return gmdate('Y-m-d',strtotime($day.' 12:00:00 UTC')+DAY_IN_SECONDS).' 00:00:00';}
function em_ago(int $seconds):string{return wp_date('Y-m-d H:i:s',time()-$seconds);}
function em_valid_day(string $d):bool{return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)&&checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4));}
function em_schedule_report():void{
    $freq=get_option('em_report_frequency','em_weekly')==='daily'?'daily':'em_weekly';
    $when=$freq==='daily'?'tomorrow 08:00':'next monday 08:00';
    $first=(new DateTimeImmutable($when,wp_timezone()))->getTimestamp();
    wp_schedule_event($first,$freq,'em_scheduled_report');
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function em_n(int $n):string{if($n>=1000000)return round($n/1000000,1).'M';if($n>=1000)return round($n/1000,1).'K';return number_format($n);}
function em_dates():array{
    $range=sanitize_text_field($_GET['range']??'30');
    if($range==='custom'){$s=sanitize_text_field($_GET['date_start']??'');$e=sanitize_text_field($_GET['date_end']??'');if(!em_valid_day($s))$s=em_days_ago(29);if(!em_valid_day($e))$e=em_today();if($s>$e)[$s,$e]=[$e,$s];}
    // "Today" is today only; "7 days" is today and the six days before it.
    else{$days=max(1,(int)$range);$s=em_days_ago($days-1);$e=em_today();}
    return[$s,$e];
}

// ── Smart post title resolver ────────────────────────────────────────────────
function em_post_label(int $pid,string $path=''):array{
    // Returns [title, url, edit_url, badge]  badge = optional HTML tag
    // Visits that are not one post or page (the blog's front page, archives, artist lists…)
    // are kept by address since 1.4.0. Older ones have no address and are shown together.
    if($pid===0){
        if($path==='')return['Homepage and other pages (older visits)',home_url('/'),'',''];
        if($path==='/')return['🏠 Homepage',home_url('/'),'',''];
        return[$path,home_url($path),'',''];
    }
    // get_post() returns trashed posts too, unlike get_post_status checks
    $post=get_post($pid);
    if(!$post){
        // Permanently deleted — nothing left in DB
        return['Unknown page','','','<span style="font-size:9px;background:rgba(248,113,113,.15);color:#f87171;padding:1px 5px;border-radius:3px;margin-left:5px">deleted</span>'];
    }
    $status=$post->post_status;
    $type=$post->post_type;
    $edit=admin_url('post.php?post='.$pid.'&action=edit');
    // Trashed posts — we can still read the title!
    if($status==='trash'){
        $title=get_the_title($pid);
        // WP appends __trashed to slug; strip it for display
        $title=preg_replace('/__trashed$/','',$title)?:$title;
        if(empty($title))$title='Post #'.$pid;
        $restore=admin_url('post.php?post='.$pid.'&action=untrash&_wpnonce='.wp_create_nonce('untrash-post_'.$pid));
        $badge='<span style="font-size:9px;background:rgba(248,113,113,.15);color:#f87171;padding:1px 5px;border-radius:3px;margin-left:5px">trashed</span>';
        return[$title,'',$edit,$badge];
    }
    $title=get_the_title($pid);
    $url=get_permalink($pid)?:'';
    if(!empty($title)){
        $badge='';
        if($status==='draft')$badge='<span style="font-size:9px;background:rgba(107,107,128,.15);color:#6b6b80;padding:1px 5px;border-radius:3px;margin-left:5px">draft</span>';
        if($status==='private')$badge='<span style="font-size:9px;background:rgba(124,111,205,.15);color:#7c6fcd;padding:1px 5px;border-radius:3px;margin-left:5px">private</span>';
        return[$title,$url,$edit,$badge];
    }
    // No title — figure out what this page actually is
    $front=get_option('page_on_front');
    $posts_page=get_option('page_for_posts');
    if((int)$front===$pid)return['🏠 Homepage',$url,$edit,''];
    if((int)$posts_page===$pid)return['📰 Blog Index',$url,$edit,''];
    if($type==='attachment')return['📎 Attachment',$url,$edit,''];
    if($type==='revision')return['↩️ Revision',$url,$edit,''];
    $type_label=ucfirst(str_replace(['_','-'],' ',$type));
    return[$type_label.' #'.$pid,$url,$edit,''];
}

// ── Country helpers ─────────────────────────────────────────────────────────
function em_country_name(string $code):string{
    $map=['AD'=>'Andorra','AE'=>'UAE','AF'=>'Afghanistan','AG'=>'Antigua','AL'=>'Albania','AM'=>'Armenia','AO'=>'Angola','AR'=>'Argentina','AT'=>'Austria','AU'=>'Australia','AZ'=>'Azerbaijan','BA'=>'Bosnia','BB'=>'Barbados','BD'=>'Bangladesh','BE'=>'Belgium','BF'=>'Burkina Faso','BG'=>'Bulgaria','BH'=>'Bahrain','BI'=>'Burundi','BJ'=>'Benin','BN'=>'Brunei','BO'=>'Bolivia','BR'=>'Brazil','BS'=>'Bahamas','BT'=>'Bhutan','BW'=>'Botswana','BY'=>'Belarus','BZ'=>'Belize','CA'=>'Canada','CD'=>'DR Congo','CF'=>'C. African Rep.','CG'=>'Congo','CH'=>'Switzerland','CI'=>'Côte d\'Ivoire','CL'=>'Chile','CM'=>'Cameroon','CN'=>'China','CO'=>'Colombia','CR'=>'Costa Rica','CU'=>'Cuba','CV'=>'Cape Verde','CY'=>'Cyprus','CZ'=>'Czechia','DE'=>'Germany','DJ'=>'Djibouti','DK'=>'Denmark','DM'=>'Dominica','DO'=>'Dom. Republic','DZ'=>'Algeria','EC'=>'Ecuador','EE'=>'Estonia','EG'=>'Egypt','ER'=>'Eritrea','ES'=>'Spain','ET'=>'Ethiopia','FI'=>'Finland','FJ'=>'Fiji','FM'=>'Micronesia','FR'=>'France','GA'=>'Gabon','GB'=>'United Kingdom','GD'=>'Grenada','GE'=>'Georgia','GH'=>'Ghana','GM'=>'Gambia','GN'=>'Guinea','GQ'=>'Eq. Guinea','GR'=>'Greece','GT'=>'Guatemala','GW'=>'Guinea-Bissau','GY'=>'Guyana','HN'=>'Honduras','HR'=>'Croatia','HT'=>'Haiti','HU'=>'Hungary','ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel','IN'=>'India','IQ'=>'Iraq','IR'=>'Iran','IS'=>'Iceland','IT'=>'Italy','JM'=>'Jamaica','JO'=>'Jordan','JP'=>'Japan','KE'=>'Kenya','KG'=>'Kyrgyzstan','KH'=>'Cambodia','KI'=>'Kiribati','KM'=>'Comoros','KN'=>'St Kitts','KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KZ'=>'Kazakhstan','LA'=>'Laos','LB'=>'Lebanon','LC'=>'St Lucia','LI'=>'Liechtenstein','LK'=>'Sri Lanka','LR'=>'Liberia','LS'=>'Lesotho','LT'=>'Lithuania','LU'=>'Luxembourg','LV'=>'Latvia','LY'=>'Libya','MA'=>'Morocco','MC'=>'Monaco','MD'=>'Moldova','ME'=>'Montenegro','MG'=>'Madagascar','MH'=>'Marshall Is.','MK'=>'N. Macedonia','ML'=>'Mali','MM'=>'Myanmar','MN'=>'Mongolia','MR'=>'Mauritania','MT'=>'Malta','MU'=>'Mauritius','MV'=>'Maldives','MW'=>'Malawi','MX'=>'Mexico','MY'=>'Malaysia','MZ'=>'Mozambique','NA'=>'Namibia','NE'=>'Niger','NG'=>'Nigeria','NI'=>'Nicaragua','NL'=>'Netherlands','NO'=>'Norway','NP'=>'Nepal','NR'=>'Nauru','NZ'=>'New Zealand','OM'=>'Oman','PA'=>'Panama','PE'=>'Peru','PG'=>'Papua New Guinea','PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Poland','PT'=>'Portugal','PW'=>'Palau','PY'=>'Paraguay','QA'=>'Qatar','RO'=>'Romania','RS'=>'Serbia','RU'=>'Russia','RW'=>'Rwanda','SA'=>'Saudi Arabia','SB'=>'Solomon Is.','SC'=>'Seychelles','SD'=>'Sudan','SE'=>'Sweden','SG'=>'Singapore','SI'=>'Slovenia','SK'=>'Slovakia','SL'=>'Sierra Leone','SM'=>'San Marino','SN'=>'Senegal','SO'=>'Somalia','SR'=>'Suriname','SS'=>'South Sudan','ST'=>'São Tomé','SV'=>'El Salvador','SY'=>'Syria','SZ'=>'Eswatini','TD'=>'Chad','TG'=>'Togo','TH'=>'Thailand','TJ'=>'Tajikistan','TL'=>'Timor-Leste','TM'=>'Turkmenistan','TN'=>'Tunisia','TO'=>'Tonga','TR'=>'Turkey','TT'=>'Trinidad','TV'=>'Tuvalu','TZ'=>'Tanzania','UA'=>'Ukraine','UG'=>'Uganda','US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan','VA'=>'Vatican','VC'=>'St Vincent','VE'=>'Venezuela','VN'=>'Vietnam','VU'=>'Vanuatu','WS'=>'Samoa','YE'=>'Yemen','ZA'=>'South Africa','ZM'=>'Zambia','ZW'=>'Zimbabwe'];
    $code=strtoupper($code);
    if(isset($map[$code]))return $map[$code];
    foreach((em_world_map()['countries']??[]) as $c)if(($c['c']??'')===$code)return (string)$c['n'];
    return $code;
}
// The pre-drawn world map shipped with the plugin (built by tools/build-world-map.mjs).
function em_world_map():?array{
    static $m=false;
    if($m!==false)return $m;
    $f=EM_PATH.'world-map.json';
    $d=is_readable($f)?json_decode((string)file_get_contents($f),true):null;
    return $m=(is_array($d)&&!empty($d['countries'])&&!empty($d['w'])&&!empty($d['h']))?$d:null;
}
function em_country_flag(string $code):string{
    $code=strtoupper($code);
    if(strlen($code)!==2||!ctype_alpha($code))return '🌐';
    $chars=[];
    foreach(str_split($code) as $c)$chars[]=mb_chr(ord($c)-65+0x1F1E6,'UTF-8');
    return implode('',$chars);
}

// ── Admin Menu ───────────────────────────────────────────────────────────────
function em_admin_menu():void{
    add_menu_page('Exposure Meter','Exposure Meter','manage_options','exposure-meter','em_page_overview','dashicons-chart-area',3);
    add_submenu_page('exposure-meter','Overview','Overview','manage_options','exposure-meter','em_page_overview');
    add_submenu_page('exposure-meter','Content','Content','manage_options','exposure-meter-content','em_page_content');
    add_submenu_page('exposure-meter','Audience','Audience','manage_options','exposure-meter-audience','em_page_audience');
    add_submenu_page('exposure-meter','Campaigns','Campaigns','manage_options','exposure-meter-campaigns','em_page_campaigns');
    add_submenu_page('exposure-meter','Real-Time','Real-Time','manage_options','exposure-meter-realtime','em_page_realtime');
    add_submenu_page('exposure-meter','Settings','Settings','manage_options','exposure-meter-settings','em_page_settings');
}
// The chart library (Chart.js 4.4.0, MIT) and the fonts (DM Sans, DM Mono, SIL Open Font
// License) are part of the plugin, so the dashboard loads nothing from other servers.
function em_admin_assets(string $hook):void{
    $ours=strpos($hook,'exposure-meter')!==false||$hook==='index.php';
    if($ours||$hook==='post.php'||$hook==='post-new.php')wp_enqueue_style('em-fonts',EM_URL.'assets/fonts/fonts.css',[],EM_VERSION);
    if($ours)wp_enqueue_script('em-chart',EM_URL.'assets/chart.umd.min.js',[],'4.4.0',true);
}


// ── CSS ──────────────────────────────────────────────────────────────────────
function em_css():void{?>
<style>
:root{--bg:#0a0a0f;--sf:#13131a;--sf2:#181820;--bd:#1e1e2e;--bd2:#252535;--ac:#c8a96e;--ac2:#7c6fcd;--tx:#e8e8f0;--mt:#6b6b80;--mt2:#4a4a5a;--gn:#4ade80;--rd:#f87171;--fn:'DM Sans',sans-serif;}
#wpwrap,#wpcontent,#wpbody,#wpbody-content{background:var(--bg)!important;}
.wrap{margin:0!important;padding:0!important;max-width:100%!important;}
.em{font-family:var(--fn);background:var(--bg);min-height:100vh;color:var(--tx);padding:0 28px 60px;}
/* top bar */
.em-bar{display:flex;align-items:center;justify-content:space-between;padding:16px 0;border-bottom:1px solid var(--bd);margin-bottom:22px;gap:10px;flex-wrap:wrap;}
.em-left{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.em-logo{display:flex;align-items:center;gap:8px;text-decoration:none;flex-shrink:0;}
.em-logo-ico{width:26px;height:26px;background:linear-gradient(135deg,var(--ac),var(--ac2));border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.em-logo-nm{font-size:14px;font-weight:600;color:var(--tx);}
.em-logo-v{font-size:9px;color:var(--mt);font-family:'DM Mono',monospace;margin-left:2px;}
.em-nav{display:flex;gap:2px;background:var(--sf);border:1px solid var(--bd);border-radius:8px;padding:3px;}
.em-nav a{padding:5px 10px;border-radius:5px;font-size:12px;font-weight:500;color:var(--mt);text-decoration:none;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:4px;}
.em-nav a:hover{color:var(--tx);background:var(--bd);}
.em-nav a.on{background:var(--ac);color:#0a0a0f;}
/* date picker */
.em-dp{display:flex;align-items:center;gap:5px;background:var(--sf);border:1px solid var(--bd);border-radius:8px;padding:5px 9px;flex-shrink:0;}
.em-dp select,.em-dp input{background:transparent;border:none;color:var(--tx);font-size:12px;font-family:var(--fn);outline:none;cursor:pointer;}
.em-dp select option{background:#13131a;color:var(--tx);}
.em-dp input[type=date]{width:115px;color-scheme:dark;}
.em-dp .sep{color:var(--mt);font-size:10px;}
.em-dp button{background:var(--ac);color:#0a0a0f;border:none;border-radius:5px;padding:4px 9px;font-size:11px;font-weight:600;cursor:pointer;font-family:var(--fn);}
/* cards */
.em-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:11px;margin-bottom:18px;}
.em-card{background:var(--sf);border:1px solid var(--bd);border-radius:12px;padding:16px 18px;position:relative;overflow:hidden;transition:border-color .2s,transform .15s;}
.em-card:hover{border-color:var(--ac);transform:translateY(-1px);}
.em-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--ac),var(--ac2));opacity:0;transition:opacity .2s;}
.em-card:hover::before{opacity:1;}
.em-card-lbl{font-size:10px;font-weight:600;color:var(--mt);text-transform:uppercase;letter-spacing:1px;margin-bottom:7px;}
.em-card-val{font-size:26px;font-weight:600;color:var(--tx);letter-spacing:-1px;line-height:1;margin-bottom:4px;}
.em-card-sub{font-size:11px;color:var(--mt);}
.em-card-ico{position:absolute;top:14px;right:14px;font-size:17px;opacity:.1;}
/* boxes */
.em-box{background:var(--sf);border:1px solid var(--bd);border-radius:12px;margin-bottom:16px;overflow:hidden;}
.em-bp{padding:18px;}
.em-ttl{font-size:12px;font-weight:600;color:var(--tx);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.em-ttl span{font-size:10px;color:var(--mt);font-weight:400;}
/* table */
.em-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.em-tbl th{padding:7px 18px;text-align:left;font-size:9px;font-weight:600;color:var(--mt);text-transform:uppercase;letter-spacing:.8px;border-bottom:1px solid var(--bd);}
.em-tbl td{padding:10px 18px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--tx);vertical-align:middle;}
.em-tbl tr:last-child td{border-bottom:none;}
.em-tbl tr:hover td{background:rgba(255,255,255,.02);}
.em-num{font-family:'DM Mono',monospace;font-size:12px;color:var(--ac);font-weight:500;}
.em-brow{display:flex;align-items:center;gap:7px;}
.em-bbar{height:3px;background:linear-gradient(90deg,var(--ac),var(--ac2));border-radius:2px;min-width:2px;flex-shrink:0;}
/* badges */
.badge{display:inline-block;padding:2px 6px;border-radius:20px;font-size:10px;font-weight:600;}
.b-search{background:rgba(200,169,110,.15);color:var(--ac);}
.b-social{background:rgba(124,111,205,.15);color:var(--ac2);}
.b-direct{background:rgba(74,222,128,.15);color:var(--gn);}
.b-other,.b-internal{background:rgba(107,107,128,.15);color:var(--mt);}
/* empty */
.em-empty{text-align:center;padding:40px 18px;color:var(--mt);}
.em-empty-ico{font-size:30px;margin-bottom:7px;opacity:.3;}
.em-empty p{font-size:12px;margin:0;line-height:1.6;}
/* live dot */
.dot{width:7px;height:7px;background:var(--gn);border-radius:50%;display:inline-block;animation:pulse 1.5s infinite;flex-shrink:0;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(.75);}}
/* heatmap */
.em-hm{display:grid;grid-template-columns:repeat(24,1fr);gap:3px;margin-bottom:6px;}
.em-hm-c{height:26px;border-radius:4px;background:var(--bd);cursor:default;position:relative;}
.em-hm-c:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 5px);left:50%;transform:translateX(-50%);background:#000;color:#fff;font-size:10px;padding:3px 7px;border-radius:4px;white-space:nowrap;z-index:99;font-family:'DM Mono',monospace;}
.em-hm-lbl{display:grid;grid-template-columns:repeat(24,1fr);gap:3px;margin-bottom:14px;}
.em-hm-l{font-size:9px;color:var(--mt);text-align:center;font-family:'DM Mono',monospace;}
/* layout grids */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:1100px){.g2{grid-template-columns:1fr;}}
/* settings */
.em-form{max-width:540px;}
.em-fr{margin-bottom:18px;}
.em-fr>label{display:block;font-size:12px;font-weight:500;color:var(--tx);margin-bottom:5px;}
.em-fr .hint{font-size:11px;color:var(--mt);margin-top:3px;line-height:1.5;}
.em-fr input[type=number],.em-fr input[type=email],.em-fr select{background:var(--sf2);border:1px solid var(--bd2);color:var(--tx);border-radius:7px;padding:8px 11px;font-size:12px;font-family:var(--fn);width:100%;outline:none;transition:border-color .15s;}
.em-fr input:focus,.em-fr select:focus{border-color:var(--ac);}
.em-fr select option{background:#13131a;}
.em-toggle{display:flex;align-items:center;gap:8px;cursor:pointer;}
.em-toggle input{display:none;}
.tt{width:34px;height:19px;background:var(--bd2);border-radius:10px;position:relative;transition:background .2s;flex-shrink:0;}
.em-toggle input:checked+.tt{background:var(--ac);}
.th{width:13px;height:13px;background:#fff;border-radius:50%;position:absolute;top:3px;left:3px;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.3);}
.em-toggle input:checked~.tt .th{transform:translateX(15px);}
.em-btn{background:var(--ac);color:#0a0a0f;border:none;border-radius:7px;padding:9px 18px;font-size:12px;font-weight:600;cursor:pointer;font-family:var(--fn);}
/* meta box */
.em-mb{background:#0a0a0f;border-radius:7px;padding:12px;font-family:'DM Sans',sans-serif;}
.em-mb-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #1e1e2e;font-size:12px;}
.em-mb-row:last-child{border-bottom:none;}
.em-mb-lbl{color:#6b6b80;}
.em-mb-val{color:#c8a96e;font-family:'DM Mono',monospace;font-weight:500;}
/* realtime rows */
.rt-row{display:grid;grid-template-columns:2fr 70px 70px 110px 90px;gap:8px;align-items:center;padding:9px 18px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px;}
.rt-row:last-child{border-bottom:none;}
.rt-row:hover{background:rgba(255,255,255,.02);}
.rt-head{font-size:9px;font-weight:600;color:var(--mt);text-transform:uppercase;letter-spacing:.8px;padding:7px 18px;border-bottom:1px solid var(--bd);}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:var(--bd2);border-radius:3px;}
</style>
<?php }

// ── Header ───────────────────────────────────────────────────────────────────
function em_header(string $active):void{
    $range=sanitize_text_field($_GET['range']??'30');
    $ds=sanitize_text_field($_GET['date_start']??'');
    $de=sanitize_text_field($_GET['date_end']??'');
    em_css();?>
<div class="em wrap">
<div class="em-bar">
    <div class="em-left">
        <a class="em-logo" href="<?php echo admin_url('admin.php?page=exposure-meter');?>">
            <div class="em-logo-ico">📡</div>
            <div><span class="em-logo-nm">Exposure Meter</span><span class="em-logo-v">v<?php echo EM_VERSION;?></span></div>
        </a>
        <nav class="em-nav"><?php
        foreach(['overview'=>['Overview','exposure-meter'],'content'=>['Content','exposure-meter-content'],'audience'=>['Audience','exposure-meter-audience'],'campaigns'=>['Campaigns','exposure-meter-campaigns'],'realtime'=>['Real-Time','exposure-meter-realtime'],'settings'=>['Settings','exposure-meter-settings']] as $k=>[$l,$sl]){
            $cls=$active===$k?' class="on"':'';
            $ico=$k==='realtime'?'<span class="dot"></span>':'';
            echo "<a href='".admin_url("admin.php?page={$sl}")."'{$cls}>{$ico}".esc_html($l)."</a>";
        }?></nav>
    </div>
    <form method="get" class="em-dp">
        <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']??'exposure-meter');?>">
        <select name="range" onchange="this.form.submit()"><?php
        foreach(['1'=>'Today','7'=>'7 days','30'=>'30 days','90'=>'90 days','365'=>'Year','custom'=>'Custom'] as $v=>$l)
            echo "<option value='{$v}'".selected($range,$v,false).">{$l}</option>";
        ?></select>
        <?php if($range==='custom'):?>
            <span class="sep">from</span><input type="date" name="date_start" value="<?php echo esc_attr($ds);?>">
            <span class="sep">to</span><input type="date" name="date_end" value="<?php echo esc_attr($de);?>">
            <button type="submit">Go</button>
        <?php endif;?>
    </form>
</div>
<?php }
function em_footer():void{echo '</div>';}


// ── OVERVIEW ─────────────────────────────────────────────────────────────────
function em_page_overview():void{
    global $wpdb;
    [$s,$e]=em_dates();
    em_purge_online_rows();
    $t=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS views,COUNT(DISTINCT visitor_hash) AS uniques,SUM(is_new_visitor) AS new_v,COUNT(DISTINCT post_id) AS pages FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s",em_from($s),em_to($e)),ARRAY_A)?:['views'=>0,'uniques'=>0,'new_v'=>0,'pages'=>0];
    $daily=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY d ORDER BY d ASC",em_from($s),em_to($e)),ARRAY_A)?:[];
    $top=$wpdb->get_results($wpdb->prepare("SELECT post_id,MAX(page_path) AS path,COUNT(*) AS views,COUNT(DISTINCT visitor_hash) AS uniques FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY post_id,IF(post_id=0,page_path,'') ORDER BY views DESC LIMIT 8",em_from($s),em_to($e)),ARRAY_A)?:[];
    $refs=$wpdb->get_results($wpdb->prepare("SELECT referrer_type,COUNT(*) AS views FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY referrer_type ORDER BY views DESC",em_from($s),em_to($e)),ARRAY_A)?:[];
    $hourly=$wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) AS hr,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY hr",em_from(em_today()),em_to(em_today())),ARRAY_A)?:[];
    $hmap=[];foreach($hourly as $h)$hmap[(int)$h['hr']]=(int)$h['v'];
    $mxh=max(array_values($hmap)?:[1]);
    $online=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}em_online");
    $mx=max(array_column($top,'views')?:[1]);
    em_header('overview');?>
    <div class="em-cards"><?php
    foreach([['👁️','Page Views',$t['views'],'Total hits'],['👤','Unique Visitors',$t['uniques'],'Each counted once per day'],['🆕','First Visits',$t['new_v'],'First page of a visitor\'s day'],['📄','Pages Hit',$t['pages'],'Distinct pages']] as[$ico,$lbl,$val,$sub]){
        echo "<div class='em-card'><div class='em-card-ico'>{$ico}</div><div class='em-card-lbl'>{$lbl}</div><div class='em-card-val'>".em_n((int)$val)."</div><div class='em-card-sub'>{$sub}</div></div>";
    }?>
    <a href="<?php echo admin_url('admin.php?page=exposure-meter-realtime');?>" style="text-decoration:none">
    <div class="em-card" style="border-color:rgba(74,222,128,.3);cursor:pointer">
        <div class="em-card-ico">🟢</div><div class="em-card-lbl">Online Now</div>
        <div class="em-card-val" style="color:var(--gn)"><?php echo $online;?></div>
        <div class="em-card-sub">Click to see who →</div>
    </div></a></div>

    <div class="em-box em-bp" style="margin-bottom:16px">
        <div class="em-ttl">Traffic Trend <span><?php echo esc_html("$s → $e");?></span></div>
        <?php if(empty($daily)):?><div class="em-empty"><div class="em-empty-ico">📈</div><p>No data yet — visit your site to generate traffic!</p></div>
        <?php else:?><canvas id="chart-main" height="70"></canvas><?php endif;?>
    </div>

    <div class="em-box em-bp" style="margin-bottom:16px">
        <div class="em-ttl">Today's Hourly Heatmap <span>Hover each cell for details</span></div>
        <div class="em-hm"><?php
        for($hr=0;$hr<24;$hr++){
            $v=$hmap[$hr]??0;$now=(int)wp_date('G');
            $al=$mxh>0&&$v>0?max(0.1,$v/$mxh):0;
            $bg=$v>0?"rgba(200,169,110,{$al})":($hr===$now?'rgba(124,111,205,.2)':'var(--bd)');
            $bx=$hr===$now?';box-shadow:0 0 0 1px var(--ac2)':'';
            $tip="{$hr}:00 — {$v} visit".($v!==1?'s':'').($hr===$now?' ◀ now':'');
            echo "<div class='em-hm-c' style='background:{$bg}{$bx}' data-tip='".esc_attr($tip)."'></div>";
        }?></div>
        <div class="em-hm-lbl"><?php for($hr=0;$hr<24;$hr++)echo "<div class='em-hm-l'>{$hr}</div>";?></div>
    </div>

    <div class="g2">
        <div class="em-box">
            <div style="padding:16px 18px 0"><div class="em-ttl">Top Content</div></div>
            <?php if(empty($top)):?><div class="em-empty"><div class="em-empty-ico">📭</div><p>No data for this period.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>Page</th><th>Views</th><th>Unique</th></tr></thead><tbody>
            <?php foreach($top as $r):
                $pid=(int)$r['post_id'];
                [$ttl,$url,$edit,$badge]=em_post_label($pid,(string)($r['path']??''));
                $pct=$mx>0?round((int)$r['views']/$mx*90):0;
            ?>
            <tr><td>
                <div class="em-brow">
                    <div class="em-bbar" style="width:<?php echo $pct;?>px"></div>
                    <?php if($url):?><a href="<?php echo esc_url($url);?>" target="_blank" style="color:var(--tx);text-decoration:none" title="<?php echo esc_attr($ttl);?>"><?php echo esc_html(mb_strimwidth($ttl,0,34,'…'));?></a><?php else:?><span style="color:var(--mt)"><?php echo esc_html(mb_strimwidth($ttl,0,34,'…'));?></span><?php endif;?>
                    <?php echo $badge;?>
                    <?php if($edit&&$url):?><a href="<?php echo esc_url($edit);?>" title="Edit post" style="color:var(--mt);text-decoration:none;margin-left:4px;font-size:11px;opacity:.5;transition:opacity .15s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.5">✏️</a><?php endif;?>
                </div>
            </td>
            <td><span class="em-num"><?php echo em_n((int)$r['views']);?></span></td>
            <td><span class="em-num"><?php echo em_n((int)$r['uniques']);?></span></td></tr>
            <?php endforeach;?></tbody></table><?php endif;?>
        </div>
        <div class="em-box">
            <div style="padding:16px 18px 0"><div class="em-ttl">Traffic Sources</div></div>
            <?php if(empty($refs)):?><div class="em-empty"><div class="em-empty-ico">🔗</div><p>No referrer data yet.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>Source</th><th>Visits</th></tr></thead><tbody>
            <?php $icos=['direct'=>'⬛','search'=>'🔍','social'=>'📱','email'=>'📧','internal'=>'🔄','other'=>'🌐'];
            foreach($refs as $r):$type=$r['referrer_type']?:'other';?>
            <tr><td><?php echo $icos[$type]??'🌐';?> <span class="badge b-<?php echo esc_attr($type);?>"><?php echo ucfirst(esc_html($type));?></span></td>
            <td><span class="em-num"><?php echo em_n((int)$r['views']);?></span></td></tr>
            <?php endforeach;?></tbody></table><?php endif;?>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        const el=document.getElementById('chart-main');if(!el||typeof Chart==='undefined')return;
        new Chart(el,{type:'line',data:{labels:<?php echo wp_json_encode(array_column($daily,'d'));?>,datasets:[
            {label:'Views',data:<?php echo wp_json_encode(array_map('intval',array_column($daily,'v')));?>,borderColor:'#c8a96e',backgroundColor:'rgba(200,169,110,.08)',fill:true,tension:.4,pointRadius:3,pointBackgroundColor:'#c8a96e'},
            {label:'Uniques',data:<?php echo wp_json_encode(array_map('intval',array_column($daily,'u')));?>,borderColor:'#7c6fcd',backgroundColor:'rgba(124,111,205,.08)',fill:true,tension:.4,pointRadius:3,pointBackgroundColor:'#7c6fcd'}
        ]},options:{responsive:true,interaction:{mode:'index',intersect:false},plugins:{legend:{labels:{color:'#6b6b80',font:{family:'DM Sans',size:11},boxWidth:10}},tooltip:{backgroundColor:'#13131a',borderColor:'#1e1e2e',borderWidth:1,titleColor:'#e8e8f0',bodyColor:'#6b6b80',padding:9}},scales:{x:{ticks:{color:'#6b6b80',font:{family:'DM Mono',size:10}},grid:{color:'rgba(255,255,255,.04)'}},y:{ticks:{color:'#6b6b80',font:{family:'DM Mono',size:10}},grid:{color:'rgba(255,255,255,.04)'},beginAtZero:true}}}});
    });
    </script>
    <?php em_footer();
}

// ── CONTENT ──────────────────────────────────────────────────────────────────
function em_page_content():void{
    global $wpdb;[$s,$e]=em_dates();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT post_id,MAX(page_path) AS path,COUNT(*) AS views,COUNT(DISTINCT visitor_hash) AS uniques,SUM(is_new_visitor) AS new_v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY post_id,IF(post_id=0,page_path,'') ORDER BY views DESC LIMIT 50",em_from($s),em_to($e)),ARRAY_A)?:[];
    $mx=max(array_column($rows,'views')?:[1]);
    em_header('content');?>
    <div class="em-box">
        <div style="padding:16px 18px 0"><div class="em-ttl">Content Performance <span><?php echo count($rows);?> pages</span></div></div>
        <?php if(empty($rows)):?><div class="em-empty"><div class="em-empty-ico">📭</div><p>No data yet. Browse your site!</p></div>
        <?php else:?><table class="em-tbl"><thead><tr><th>#</th><th>Page</th><th>Type</th><th>Views</th><th>Uniques</th><th>New</th><th>Bar</th></tr></thead><tbody>
        <?php foreach($rows as $i=>$r):
            $pid=(int)$r['post_id'];
            [$ttl,$url,$edit,$badge]=em_post_label($pid,(string)($r['path']??''));
            $type=$pid?(get_post_type($pid)?:'unknown'):((($r['path']??'')==='/')?'homepage':'other page');
            $pct=$mx>0?round((int)$r['views']/$mx*140):0;?>
        <tr>
            <td style="color:var(--mt);font-family:'DM Mono',monospace;font-size:10px"><?php echo $i+1;?></td>
            <td>
                <?php if($url):?><a href="<?php echo esc_url($url);?>" target="_blank" style="color:var(--tx);text-decoration:none" title="<?php echo esc_attr($ttl);?>"><?php echo esc_html(mb_strimwidth($ttl,0,50,'…'));?></a><?php else:?><span style="color:var(--mt)"><?php echo esc_html(mb_strimwidth($ttl,0,50,'…'));?></span><?php endif;?>
                <?php echo $badge;?>
                <?php if($edit&&$url):?><a href="<?php echo esc_url($edit);?>" title="Edit post" style="color:var(--mt);text-decoration:none;margin-left:4px;font-size:11px;opacity:.5" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.5">✏️</a><?php endif;?>
            </td>
            <td><span style="font-size:10px;color:var(--mt);font-family:'DM Mono',monospace"><?php echo esc_html($type);?></span></td>
            <td><span class="em-num"><?php echo em_n((int)$r['views']);?></span></td>
            <td><span class="em-num"><?php echo em_n((int)$r['uniques']);?></span></td>
            <td><span class="em-num"><?php echo em_n((int)$r['new_v']);?></span></td>
            <td><div class="em-bbar" style="width:<?php echo $pct;?>px"></div></td>
        </tr>
        <?php endforeach;?></tbody></table><?php endif;?>
    </div>
    <?php em_footer();
}

// ── AUDIENCE ─────────────────────────────────────────────────────────────────
function em_page_audience():void{
    global $wpdb;[$s,$e]=em_dates();
    $countries=$wpdb->get_results($wpdb->prepare("SELECT country_code,COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s AND country_code!='' GROUP BY country_code ORDER BY v DESC LIMIT 300",em_from($s),em_to($e)),ARRAY_A)?:[];
    $devices=$wpdb->get_results($wpdb->prepare("SELECT device,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY device ORDER BY v DESC",em_from($s),em_to($e)),ARRAY_A)?:[];
    $browsers=$wpdb->get_results($wpdb->prepare("SELECT browser,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s AND browser!='' GROUP BY browser ORDER BY v DESC LIMIT 8",em_from($s),em_to($e)),ARRAY_A)?:[];
    $os=$wpdb->get_results($wpdb->prepare("SELECT os,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s AND os!='' GROUP BY os ORDER BY v DESC LIMIT 8",em_from($s),em_to($e)),ARRAY_A)?:[];
    $total=max(array_sum(array_column($devices,'v')),1);
    $mxc=max(array_column($countries,'v')?:[1]);
    $mxb=max(array_column($browsers,'v')?:[1]);
    $mxo=max(array_column($os,'v')?:[1]);
    // For the world map: visits and a "flag name" label per country code
    $country_data=[];$country_labels=[];
    foreach($countries as $c){
        $cc=strtoupper($c['country_code']);
        $country_data[$cc]=(int)$c['v'];
        $country_labels[$cc]=em_country_flag($cc).' '.em_country_name($cc);
    }
    $map=em_world_map();
    em_header('audience');?>

    <!-- World Map Choropleth -->
    <div class="em-box em-bp" style="margin-bottom:16px">
        <div class="em-ttl">🗺️ World Visitor Map <span>Brighter = more visits · point at a country for its count</span></div>
        <?php if(empty($countries)):?>
            <div class="em-empty"><div class="em-empty-ico">🌍</div><p>No geo data yet. Visit your site from different countries to populate the map!</p></div>
        <?php elseif(!$map):?>
            <div class="em-empty"><div class="em-empty-ico">🌍</div><p>The map file (world-map.json) is missing from the plugin folder. The country table below is unaffected.</p></div>
        <?php else:?>
        <div id="em-world-map" style="width:100%;background:var(--sf);border-radius:8px;position:relative">
            <svg id="em-svg-map" viewBox="0 0 <?php echo (int)$map['w'];?> <?php echo (int)$map['h'];?>" role="img" aria-label="World map of visits by country" style="width:100%;height:auto;display:block"></svg>
            <div id="em-map-tip" style="position:absolute;background:#000;color:#fff;font-size:11px;padding:5px 9px;border-radius:5px;pointer-events:none;display:none;font-family:'DM Mono',monospace;white-space:nowrap;z-index:10"></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:10px;justify-content:flex-end">
            <div style="width:12px;height:8px;border-radius:2px;background:#1a1a2a;border:1px solid rgba(255,255,255,.12)"></div>
            <span style="font-size:10px;color:var(--mt);margin-right:10px">No visits</span>
            <span style="font-size:10px;color:var(--mt)">Few</span>
            <div style="width:120px;height:8px;border-radius:4px;background:linear-gradient(90deg,#6e5c3e,#c8a96e)"></div>
            <span style="font-size:10px;color:var(--mt)">Most visits</span>
        </div>
        <?php endif;?>
    </div>

    <div class="g2" style="margin-bottom:16px">
        <!-- Country table with flags -->
        <div class="em-box">
            <div style="padding:16px 18px 0"><div class="em-ttl">Top Countries</div></div>
            <?php if(empty($countries)):?><div class="em-empty"><div class="em-empty-ico">🌍</div><p>No geo data yet.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>Country</th><th>Views</th><th>Unique</th><th>Share</th></tr></thead><tbody>
            <?php foreach(array_slice($countries,0,15) as $c):
                $flag=em_country_flag($c['country_code']);
                $name=em_country_name($c['country_code']);
                $pct=$mxc>0?round((int)$c['v']/$mxc*80):0;
                $share=$total>0?round((int)$c['v']/array_sum(array_column($countries,'v'))*100):0;
            ?><tr>
                <td><span style="font-size:16px;margin-right:6px"><?php echo $flag;?></span><span style="font-size:12px"><?php echo esc_html($name);?></span></td>
                <td><span class="em-num"><?php echo em_n((int)$c['v']);?></span></td>
                <td><span class="em-num"><?php echo em_n((int)$c['u']);?></span></td>
                <td><div class="em-brow"><div class="em-bbar" style="width:<?php echo $pct;?>px"></div><span style="font-size:10px;color:var(--mt)"><?php echo $share;?>%</span></div></td>
            </tr><?php endforeach;?></tbody></table>
            <?php endif;?>
        </div>

        <!-- Devices -->
        <div class="em-box em-bp"><div class="em-ttl">Devices</div>
            <?php if(empty($devices)):?><div class="em-empty"><div class="em-empty-ico">📱</div><p>No device data yet.</p></div>
            <?php else:?><canvas id="chart-dev" height="150"></canvas><?php endif;?>
        </div>
    </div>

    <div class="g2">
        <div class="em-box"><div style="padding:16px 18px 0"><div class="em-ttl">Browsers</div></div>
            <?php if(empty($browsers)):?><div class="em-empty"><div class="em-empty-ico">🌐</div><p>No data.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>Browser</th><th>Views</th><th>Share</th></tr></thead><tbody>
            <?php foreach($browsers as $b):?><tr><td><?php echo esc_html($b['browser']);?></td><td><span class="em-num"><?php echo em_n((int)$b['v']);?></span></td><td><div class="em-brow"><div class="em-bbar" style="width:<?php echo round((int)$b['v']/$mxb*70);?>px"></div><span style="font-size:10px;color:var(--mt)"><?php echo round((int)$b['v']/$total*100);?>%</span></div></td></tr><?php endforeach;?></tbody></table><?php endif;?>
        </div>
        <div class="em-box"><div style="padding:16px 18px 0"><div class="em-ttl">Operating Systems</div></div>
            <?php if(empty($os)):?><div class="em-empty"><div class="em-empty-ico">💻</div><p>No data.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>OS</th><th>Views</th><th>Share</th></tr></thead><tbody>
            <?php foreach($os as $o):?><tr><td><?php echo esc_html($o['os']);?></td><td><span class="em-num"><?php echo em_n((int)$o['v']);?></span></td><td><div class="em-brow"><div class="em-bbar" style="width:<?php echo round((int)$o['v']/$mxo*70);?>px;background:linear-gradient(90deg,var(--ac2),var(--ac))"></div><span style="font-size:10px;color:var(--mt)"><?php echo round((int)$o['v']/$total*100);?>%</span></div></td></tr><?php endforeach;?></tbody></table><?php endif;?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded',function(){
        // ── Device donut chart ──
        const dc=document.getElementById('chart-dev');
        if(dc&&typeof Chart!=='undefined'){new Chart(dc,{type:'doughnut',data:{labels:<?php echo wp_json_encode(array_column($devices,'device'));?>,datasets:[{data:<?php echo wp_json_encode(array_map('intval',array_column($devices,'v')));?>,backgroundColor:['#c8a96e','#7c6fcd','#4ade80','#f87171'],borderWidth:0,hoverOffset:4}]},options:{cutout:'68%',plugins:{legend:{position:'bottom',labels:{color:'#6b6b80',padding:12,font:{family:'DM Sans',size:11},boxWidth:9}},tooltip:{backgroundColor:'#13131a',borderColor:'#1e1e2e',borderWidth:1,titleColor:'#e8e8f0',bodyColor:'#6b6b80'}}}});}

        // ── World Map ──
        // The shapes come ready-drawn from world-map.json; this only colours them.
        const mapEl=document.getElementById('em-svg-map');
        if(!mapEl)return;
        const world=<?php echo wp_json_encode($map?:['countries'=>[]]);?>;
        const visits=<?php echo wp_json_encode((object)$country_data);?>;
        const labels=<?php echo wp_json_encode((object)$country_labels);?>;
        const maxVal=<?php echo max(1,(int)$mxc);?>;
        const svgNS='http://www.w3.org/2000/svg';
        const frame=document.getElementById('em-world-map');
        const tip=document.getElementById('em-map-tip');

        function shade(v){
            // from #6e5c3e (a single visit, still clearly lit) to #c8a96e (most visits);
            // square root, so countries with a handful of visits do not fade into the dark
            const t=Math.sqrt(v/maxVal);
            const mix=(a,b)=>Math.round(a+(b-a)*t);
            return 'rgb('+mix(0x6e,0xc8)+','+mix(0x5c,0xa9)+','+mix(0x3e,0x6e)+')';
        }
        function placeTip(e){
            const r=frame.getBoundingClientRect();
            let x=e.clientX-r.left+12,y=e.clientY-r.top-34;
            if(x+tip.offsetWidth>r.width-4)x=e.clientX-r.left-tip.offsetWidth-12;
            if(y<4)y=e.clientY-r.top+18;
            tip.style.left=Math.max(4,x)+'px';tip.style.top=y+'px';
        }
        function wire(el,text,rest,hover){
            el.addEventListener('mouseenter',function(e){el.setAttribute('fill',hover);tip.textContent=text;tip.style.display='block';placeTip(e);});
            el.addEventListener('mousemove',placeTip);
            el.addEventListener('mouseleave',function(){el.setAttribute('fill',rest);tip.style.display='none';});
        }
        const plain=[],lit=[],dots=[];
        world.countries.forEach(function(c){
            const v=c.c?(visits[c.c]||0):0;
            const text=(labels[c.c]||c.n)+' — '+(v?v.toLocaleString()+' visit'+(v===1?'':'s'):'no visits');
            const fill=v?shade(v):'#1a1a2a';
            if(c.d){
                const p=document.createElementNS(svgNS,'path');
                p.setAttribute('d',c.d);
                p.setAttribute('fill',fill);
                p.setAttribute('stroke',v?'rgba(200,169,110,.5)':'rgba(255,255,255,.08)');
                p.setAttribute('stroke-width','.6');
                p.setAttribute('stroke-linejoin','round');
                p.setAttribute('vector-effect','non-scaling-stroke');
                wire(p,text,fill,v?'#e8c87a':'#252535');
                (v?lit:plain).push(p);
            }
            // Countries too small to see get a dot, but only when they have visits.
            if(c.m&&v){
                const d=document.createElementNS(svgNS,'circle');
                d.setAttribute('cx',c.m[0]);d.setAttribute('cy',c.m[1]);d.setAttribute('r','3.2');
                d.setAttribute('fill',fill);
                d.setAttribute('stroke','#0a0a0f');d.setAttribute('stroke-width','1');
                d.setAttribute('vector-effect','non-scaling-stroke');
                wire(d,text,fill,'#e8c87a');
                dots.push(d);
            }
        });
        // Countries with visits go on top, so their outline is not covered by neighbours.
        plain.concat(lit,dots).forEach(function(el){mapEl.appendChild(el);});
    });
    </script>
    <?php em_footer();
}

// ── CAMPAIGNS ────────────────────────────────────────────────────────────────
function em_page_campaigns():void{
    global $wpdb;[$s,$e]=em_dates();
    $camps=$wpdb->get_results($wpdb->prepare("SELECT utm_campaign,utm_source,utm_medium,COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE utm_campaign!='' AND created_at>=%s AND created_at<%s GROUP BY utm_campaign,utm_source,utm_medium ORDER BY v DESC LIMIT 30",em_from($s),em_to($e)),ARRAY_A)?:[];
    $refs=$wpdb->get_results($wpdb->prepare("SELECT referrer,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE referrer!='' AND referrer_type!='internal' AND created_at>=%s AND created_at<%s GROUP BY referrer ORDER BY v DESC LIMIT 20",em_from($s),em_to($e)),ARRAY_A)?:[];
    em_header('campaigns');?>
    <div class="g2">
        <div class="em-box"><div style="padding:16px 18px 0"><div class="em-ttl">UTM Campaigns <span>Tag links with ?utm_campaign= to track here</span></div></div>
            <?php if(empty($camps)):?><div class="em-empty"><div class="em-empty-ico">🎯</div><p>No campaign data yet.<br><code style="color:var(--ac);font-size:10px;background:var(--bd);padding:2px 5px;border-radius:3px">?utm_campaign=rio-ep&utm_source=instagram&utm_medium=social</code></p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>Campaign</th><th>Source</th><th>Medium</th><th>Visits</th><th>Unique</th></tr></thead><tbody>
            <?php foreach($camps as $c):?><tr><td style="font-weight:500"><?php echo esc_html($c['utm_campaign']);?></td><td><span style="color:var(--mt);font-size:11px"><?php echo esc_html($c['utm_source']);?></span></td><td><span style="color:var(--mt);font-size:11px"><?php echo esc_html($c['utm_medium']);?></span></td><td><span class="em-num"><?php echo em_n((int)$c['v']);?></span></td><td><span class="em-num"><?php echo em_n((int)$c['u']);?></span></td></tr><?php endforeach;?></tbody></table><?php endif;?>
        </div>
        <div class="em-box"><div style="padding:16px 18px 0"><div class="em-ttl">Top Referrer URLs</div></div>
            <?php if(empty($refs)):?><div class="em-empty"><div class="em-empty-ico">🔗</div><p>No referrer URLs yet.</p></div>
            <?php else:?><table class="em-tbl"><thead><tr><th>URL</th><th>Visits</th></tr></thead><tbody>
            <?php foreach($refs as $r):?><tr><td style="font-size:11px;word-break:break-all"><a href="<?php echo esc_url($r['referrer']);?>" target="_blank" style="color:var(--mt);text-decoration:none" title="<?php echo esc_attr($r['referrer']);?>"><?php echo esc_html(mb_strimwidth($r['referrer'],0,52,'…'));?></a></td><td><span class="em-num"><?php echo em_n((int)$r['v']);?></span></td></tr><?php endforeach;?></tbody></table><?php endif;?>
        </div>
    </div>
    <?php em_footer();
}


// ── REAL-TIME ────────────────────────────────────────────────────────────────
function em_page_realtime():void{
    global $wpdb;
    em_purge_online_rows();
    $online=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}em_online ORDER BY last_seen DESC",ARRAY_A)?:[];
    $hourly=$wpdb->get_results($wpdb->prepare("SELECT HOUR(created_at) AS hr,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY hr",em_from(em_today()),em_to(em_today())),ARRAY_A)?:[];
    $hmap=[];foreach($hourly as $h)$hmap[(int)$h['hr']]=(int)$h['v'];
    $mxh=max(array_values($hmap)?:[1]);
    $weekly=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS d,HOUR(created_at) AS hr,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s GROUP BY d,hr ORDER BY d ASC,hr ASC",em_from(em_days_ago(6))),ARRAY_A)?:[];
    $wmap=[];foreach($weekly as $w)$wmap[$w['d']][(int)$w['hr']]=(int)$w['v'];
    $mxw=1;foreach($wmap as $day)$mxw=max($mxw,max($day));
    $page_counts=[];foreach($online as $v){$k=$v['page_title']?:'Unknown';$page_counts[$k]=($page_counts[$k]??0)+1;}arsort($page_counts);
    $mobile_count=count(array_filter($online,fn($v)=>$v['device']==='mobile'));
    em_header('realtime');?>

    <div class="em-cards" style="margin-bottom:16px">
        <div class="em-card" style="border-color:rgba(74,222,128,.3)"><div class="em-card-ico">🟢</div><div class="em-card-lbl">Online Now</div><div class="em-card-val" style="color:var(--gn)"><?php echo count($online);?></div><div class="em-card-sub">Refreshes in <span id="rt-cd">30</span>s</div></div>
        <div class="em-card"><div class="em-card-ico">📄</div><div class="em-card-lbl">Pages Active</div><div class="em-card-val"><?php echo count($page_counts);?></div><div class="em-card-sub">Distinct pages open</div></div>
        <div class="em-card"><div class="em-card-ico">📱</div><div class="em-card-lbl">Mobile</div><div class="em-card-val"><?php echo $mobile_count;?></div><div class="em-card-sub">Of current visitors</div></div>
    </div>

    <div class="em-box em-bp" style="margin-bottom:16px">
        <div class="em-ttl"><span class="dot"></span> Today's Traffic Heatmap <span>Each cell = 1 hour · current hour highlighted</span></div>
        <div class="em-hm"><?php
        for($hr=0;$hr<24;$hr++){
            $v=$hmap[$hr]??0;$now=(int)wp_date('G');
            $al=$mxh>0&&$v>0?max(0.1,$v/$mxh):0;
            $bg=$v>0?"rgba(200,169,110,{$al})":($hr===$now?'rgba(124,111,205,.25)':'var(--bd)');
            $bx=$hr===$now?';box-shadow:0 0 0 1px var(--ac2)':'';
            $tip="{$hr}:00 — {$v} visit".($v!==1?'s':'').($hr===$now?' ◀ now':'');
            echo "<div class='em-hm-c' style='background:{$bg}{$bx}' data-tip='".esc_attr($tip)."'></div>";
        }?></div>
        <div class="em-hm-lbl"><?php for($hr=0;$hr<24;$hr++)echo "<div class='em-hm-l'>{$hr}</div>";?></div>

        <?php if(!empty($wmap)):?>
        <div class="em-ttl" style="margin-top:14px;margin-bottom:8px">Last 7 Days — Activity by Hour</div>
        <?php foreach($wmap as $day=>$hours):?>
        <div style="display:grid;grid-template-columns:65px 1fr;gap:8px;align-items:center;margin-bottom:4px">
            <span style="font-size:9px;color:var(--mt);font-family:'DM Mono',monospace;text-align:right"><?php echo esc_html($day);?></span>
            <div style="display:grid;grid-template-columns:repeat(24,1fr);gap:2px"><?php
            for($hr=0;$hr<24;$hr++){
                $v=$hours[$hr]??0;
                $al=$mxw>0&&$v>0?max(0.1,$v/$mxw):0;
                $bg=$v>0?"rgba(200,169,110,{$al})":'var(--bd)';
                $tip=gmdate('D',strtotime($day))." {$hr}:00 — {$v}";
                echo "<div class='em-hm-c' style='height:12px;border-radius:2px;background:{$bg}' data-tip='".esc_attr($tip)."'></div>";
            }?></div>
        </div>
        <?php endforeach;endif;?>
    </div>

    <?php if(!empty($page_counts)):?>
    <div class="em-box em-bp" style="margin-bottom:16px">
        <div class="em-ttl">Pages Being Viewed Right Now</div>
        <?php $mxp=max($page_counts);foreach($page_counts as $ptitle=>$cnt):?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:9px">
            <div class="em-bbar" style="width:<?php echo round($cnt/$mxp*180);?>px;height:5px"></div>
            <span style="font-size:12px;color:var(--tx)"><?php echo esc_html(mb_strimwidth($ptitle,0,48,'…'));?></span>
            <span class="em-num" style="margin-left:auto"><?php echo $cnt;?></span>
        </div>
        <?php endforeach;?>
    </div>
    <?php endif;?>

    <div class="em-box">
        <div style="padding:16px 18px 0"><div class="em-ttl"><span class="dot"></span> Live Visitor Table <span id="rt-status">Refreshes in 30s</span></div></div>
        <?php if(empty($online)):?><div class="em-empty"><div class="em-empty-ico">👻</div><p>Nobody online right now. Check back soon!</p></div>
        <?php else:?>
        <div class="rt-head" style="display:grid;grid-template-columns:2fr 140px 80px 110px 90px;gap:8px;padding:7px 18px"><span>Page</span><span>Country</span><span>Device</span><span>From</span><span>Last Seen</span></div>
        <?php foreach($online as $v):
            $pid=(int)$v['post_id'];
            $ttl=$v['page_title']?:($pid?get_the_title($pid):get_bloginfo('name'));
            $url=$pid?get_permalink($pid):home_url();
            $ago=human_time_diff(strtotime($v['last_seen']),current_time('timestamp'));
        ?><div class="rt-row" style="grid-template-columns:2fr 140px 80px 110px 90px">
            <a href="<?php echo esc_url($url);?>" target="_blank" style="color:var(--tx);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html(mb_strimwidth($ttl,0,42,'…'));?></a>
            <span style="color:var(--mt);font-size:11px"><?php echo $v['country_code']?em_country_flag($v['country_code']).' '.esc_html(em_country_name($v['country_code'])):'—';?></span>
            <span style="color:var(--mt)"><?php echo esc_html(ucfirst($v['device'])?:'—');?></span>
            <span style="font-size:10px;color:var(--mt);overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr($v['referrer']);?>"><?php echo $v['referrer']?esc_html(mb_strimwidth((string)parse_url($v['referrer'],PHP_URL_HOST),0,18,'…')):'Direct';?></span>
            <span style="font-size:10px;color:var(--mt2)"><?php echo esc_html($ago);?> ago</span>
        </div>
        <?php endforeach;endif;?>
    </div>
    <script>
    let secs=30;
    const cd=document.getElementById('rt-cd');
    const st=document.getElementById('rt-status');
    setInterval(()=>{
        secs--;
        if(cd)cd.textContent=secs;
        if(st)st.textContent='Refreshes in '+secs+'s';
        if(secs<=0)location.reload();
    },1000);
    </script>
    <?php em_footer();
}

// ── SETTINGS ─────────────────────────────────────────────────────────────────
function em_page_settings():void{
    if(isset($_POST['em_save'])&&check_admin_referer('em_settings')){
        update_option('em_cache_mode',isset($_POST['em_cache_mode']));
        update_option('em_report_enabled',isset($_POST['em_report_enabled']));
        update_option('em_retention_days',max(30,(int)($_POST['em_retention_days']??365)));
        update_option('em_report_email',sanitize_email($_POST['em_report_email']??''));
        update_option('em_report_frequency',($_POST['em_report_frequency']??'')==='daily'?'daily':'em_weekly');
        $roles=isset($_POST['em_exclude_roles'])?array_map('sanitize_text_field',(array)$_POST['em_exclude_roles']):[];
        update_option('em_exclude_roles',$roles);
        wp_clear_scheduled_hook('em_scheduled_report');
        if(get_option('em_report_enabled'))em_schedule_report();
        echo '<div style="position:fixed;top:30px;right:30px;background:var(--gn);color:#0a0a0f;padding:11px 18px;border-radius:9px;font-weight:600;font-size:12px;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.4)">✓ Settings saved</div>';
    }
    $cache=get_option('em_cache_mode',false);
    $ret=get_option('em_retention_days',365);$exr=(array)get_option('em_exclude_roles',['administrator','editor']);
    $remail=get_option('em_report_email',get_option('admin_email'));
    $rfreq=get_option('em_report_frequency','em_weekly');$renabled=get_option('em_report_enabled',false);
    global $wpdb,$wp_version;
    $vc=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits");
    $old=$wpdb->get_var("SELECT MIN(created_at) FROM {$wpdb->prefix}em_visits");
    em_header('settings');?>
    <div class="g2">
        <div><form method="post" class="em-form"><?php wp_nonce_field('em_settings');?>
            <div style="font-size:10px;font-weight:600;color:var(--mt);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px">Tracking</div>
            <div class="em-fr"><label>IP Addresses</label><div class="hint" style="margin-top:0">Never stored. A visitor's address is shortened as soon as it arrives, and only the shortened form is used: to look up the country, and to tell one day's visitors apart.</div></div>
            <div class="em-fr"><label>Cache-Compatible Mode</label><label class="em-toggle"><input type="checkbox" name="em_cache_mode" <?php checked($cache);?>><div class="tt"><div class="th"></div></div><span style="font-size:11px;color:var(--mt)">Count visits with a small script in the page, so pages served from a cache are counted too</span></label><?php $cachers=em_page_cache_plugins();if($cachers&&!$cache):?><div class="hint" style="color:var(--ac)">This site uses <?php echo esc_html(implode(', ',$cachers));?>. With this off, visits to cached pages are not counted. Turn it on.</div><?php endif;?></div>
            <div class="em-fr"><label>Data Retention (days)</label><input type="number" name="em_retention_days" value="<?php echo (int)$ret;?>" min="30" max="3650"><div class="hint">Raw logs older than this are auto-deleted. Aggregates kept forever.</div></div>
            <div class="em-fr"><label>Exclude Roles from Tracking</label><?php foreach(wp_roles()->get_names() as $rk=>$rn):?><label style="display:flex;align-items:center;gap:7px;margin-bottom:6px;cursor:pointer;font-size:11px;color:var(--mt)"><input type="checkbox" name="em_exclude_roles[]" value="<?php echo esc_attr($rk);?>" <?php checked(in_array($rk,$exr,true));?> style="accent-color:var(--ac)"><?php echo esc_html($rn);?></label><?php endforeach;?></div>
            <div style="font-size:10px;font-weight:600;color:var(--mt);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;margin-top:6px;padding-top:18px;border-top:1px solid var(--bd)">Email Reports</div>
            <div class="em-fr"><label>Enable Reports</label><label class="em-toggle"><input type="checkbox" name="em_report_enabled" <?php checked($renabled);?>><div class="tt"><div class="th"></div></div><span style="font-size:11px;color:var(--mt)">Send automatic stats reports by email</span></label></div>
            <div class="em-fr"><label>Report Email</label><input type="email" name="em_report_email" value="<?php echo esc_attr($remail);?>"></div>
            <div class="em-fr"><label>Frequency</label><select name="em_report_frequency"><option value="daily" <?php selected($rfreq,'daily');?>>Daily</option><option value="em_weekly" <?php selected($rfreq,'em_weekly');?>>Weekly (Monday)</option></select></div>
            <button type="submit" name="em_save" class="em-btn">Save Settings</button>
        </form></div>
        <div>
            <div class="em-box em-bp"><div class="em-ttl">System Info</div>
                <table class="em-tbl"><tbody>
                    <tr><td style="color:var(--mt)">Total visits</td><td><span class="em-num"><?php echo em_n($vc);?></span></td></tr>
                    <tr><td style="color:var(--mt)">Tracking since</td><td><span style="font-size:11px"><?php echo $old?esc_html(gmdate('M j, Y',strtotime($old))):'—';?></span></td></tr>
                    <tr><td style="color:var(--mt)">Plugin version</td><td><span style="font-family:'DM Mono',monospace;font-size:11px;color:var(--ac)"><?php echo EM_VERSION;?></span></td></tr>
                    <tr><td style="color:var(--mt)">PHP</td><td><span style="font-family:'DM Mono',monospace;font-size:11px"><?php echo PHP_VERSION;?></span></td></tr>
                    <tr><td style="color:var(--mt)">WordPress</td><td><span style="font-family:'DM Mono',monospace;font-size:11px"><?php echo $wp_version;?></span></td></tr>
                </tbody></table>
            </div>
            <div class="em-box em-bp"><div class="em-ttl">Test Report</div>
                <p style="font-size:11px;color:var(--mt);line-height:1.6;margin:0 0 10px">Send a test report to preview the email format.</p>
                <form method="post"><?php wp_nonce_field('em_test_report');?>
                    <button type="submit" name="em_test_report" class="em-btn" style="font-size:11px;padding:7px 14px">Send Test Email</button>
                </form>
                <?php if(isset($_POST['em_test_report'])&&check_admin_referer('em_test_report')){em_send_report();echo '<p style="font-size:11px;color:var(--gn);margin-top:8px">✓ Sent to '.esc_html($remail).'</p>';}?>
            </div>
        </div>
    </div>
    <?php em_footer();
}


// Page cache plugins that serve pages without running WordPress's page code.
function em_page_cache_plugins():array{
    $found=[];
    if(defined('LSCWP_V'))$found[]='LiteSpeed Cache';
    if(defined('WP_ROCKET_VERSION'))$found[]='WP Rocket';
    if(defined('W3TC'))$found[]='W3 Total Cache';
    if(defined('WPCACHEHOME'))$found[]='WP Super Cache';
    if(defined('WPFC_MAIN_PATH'))$found[]='WP Fastest Cache';
    return $found;
}

// ── DASHBOARD WIDGET ─────────────────────────────────────────────────────────
function em_register_dashboard_widget():void{
    wp_add_dashboard_widget('em_widget','📡 Exposure Meter','em_render_dashboard_widget');
}
function em_render_dashboard_widget():void{
    global $wpdb;
    em_purge_online_rows();
    $today=em_today();$week=em_days_ago(6);
    $td=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s",em_from($today),em_to($today)),ARRAY_A)?:['v'=>0,'u'=>0];
    $wk=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE created_at>=%s",em_from($week)),ARRAY_A)?:['v'=>0,'u'=>0];
    $online=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}em_online");
    $top=$wpdb->get_results($wpdb->prepare("SELECT post_id,MAX(page_path) AS path,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY post_id,IF(post_id=0,page_path,'') ORDER BY v DESC LIMIT 4",em_from($today),em_to($today)),ARRAY_A)?:[];
    $spark=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s GROUP BY d ORDER BY d ASC",em_from($week)),ARRAY_A)?:[];
    ?>
    <style>
    #em_widget .em-w{background:#0a0a0f;border-radius:9px;padding:14px;margin:-12px -12px 0;font-family:'DM Sans',sans-serif;color:#e8e8f0;}
    #em_widget .em-w-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:12px;}
    #em_widget .em-w-card{background:#13131a;border:1px solid #1e1e2e;border-radius:8px;padding:10px 12px;text-align:center;}
    #em_widget .em-w-val{font-size:20px;font-weight:600;color:#e8e8f0;letter-spacing:-.5px;line-height:1;}
    #em_widget .em-w-lbl{font-size:9px;color:#6b6b80;text-transform:uppercase;letter-spacing:.8px;margin-top:3px;}
    #em_widget .em-w-row{display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #1e1e2e;font-size:11px;}
    #em_widget .em-w-row:last-child{border-bottom:none;}
    #em_widget .em-w-sec{font-size:9px;font-weight:600;color:#6b6b80;text-transform:uppercase;letter-spacing:.8px;margin-bottom:7px;}
    </style>
    <div class="em-w">
        <div class="em-w-grid">
            <div class="em-w-card"><div class="em-w-val" style="color:#4ade80"><?php echo $online;?></div><div class="em-w-lbl">🟢 Online</div></div>
            <div class="em-w-card"><div class="em-w-val"><?php echo em_n((int)$td['v']);?></div><div class="em-w-lbl">👁 Today</div></div>
            <div class="em-w-card"><div class="em-w-val"><?php echo em_n((int)$wk['v']);?></div><div class="em-w-lbl">📅 7 Days</div></div>
        </div>
        <?php if(!empty($spark)):?>
        <div style="margin-bottom:12px"><div class="em-w-sec">7-Day Trend</div><canvas id="em-wgt-spark" height="45"></canvas></div>
        <?php endif;?>
        <?php if(!empty($top)):?>
        <div><div class="em-w-sec">Top Pages Today</div>
        <?php foreach($top as $r):$pid=(int)$r['post_id'];[$ttl,$url]=em_post_label($pid,(string)($r['path']??''));?>
        <div class="em-w-row"><a href="<?php echo esc_url($url);?>" target="_blank" style="color:#e8e8f0;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:72%"><?php echo esc_html(mb_strimwidth($ttl,0,32,'…'));?></a><span style="color:#c8a96e;font-family:'DM Mono',monospace;font-size:11px"><?php echo em_n((int)$r['v']);?></span></div>
        <?php endforeach;?></div>
        <?php endif;?>
        <div style="text-align:center;padding-top:10px;border-top:1px solid #1e1e2e;margin-top:10px"><a href="<?php echo admin_url('admin.php?page=exposure-meter');?>" style="color:#c8a96e;font-size:11px;text-decoration:none;font-weight:500">Full Dashboard →</a></div>
    </div>
    <?php if(!empty($spark)):?>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        if(typeof Chart==='undefined')return;
        const c=document.getElementById('em-wgt-spark');if(!c)return;
        new Chart(c,{type:'line',data:{labels:<?php echo wp_json_encode(array_column($spark,'d'));?>,datasets:[{data:<?php echo wp_json_encode(array_map('intval',array_column($spark,'v')));?>,borderColor:'#c8a96e',backgroundColor:'rgba(200,169,110,.1)',fill:true,tension:.4,pointRadius:0,borderWidth:2}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{backgroundColor:'#13131a',borderColor:'#1e1e2e',borderWidth:1,titleColor:'#e8e8f0',bodyColor:'#6b6b80'}},scales:{x:{display:false},y:{display:false,beginAtZero:true}}}});
    });
    </script>
    <?php endif;
}

// ── META BOX ─────────────────────────────────────────────────────────────────
function em_register_metabox():void{
    foreach(get_post_types(['public'=>true]) as $type)
        add_meta_box('em_post_stats','📡 Exposure Meter','em_render_metabox',$type,'side','default');
}
function em_render_metabox(WP_Post $post):void{
    global $wpdb;$pid=$post->ID;
    $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE post_id=%d",$pid));
    $today=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s",$pid,em_from(em_today())));
    $week=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s",$pid,em_from(em_days_ago(6))));
    $month=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s",$pid,em_from(em_days_ago(29))));
    $uniq=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_hash) FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s",$pid,em_from(em_days_ago(29))));
    $peak=$wpdb->get_row($wpdb->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE post_id=%d GROUP BY DATE(created_at) ORDER BY v DESC LIMIT 1",$pid),ARRAY_A);
    // The dashboard's styles are not loaded on the editing screen, so the box brings its own.
    echo '<style>#em_post_stats .em-mb{background:#0a0a0f;border-radius:7px;padding:12px}#em_post_stats .em-mb-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #1e1e2e;font-size:12px}#em_post_stats .em-mb-row:last-child{border-bottom:none}#em_post_stats .em-mb-lbl{color:#9a9ab0}#em_post_stats .em-mb-val{color:#c8a96e;font-family:"DM Mono",ui-monospace,monospace;font-weight:500}</style>';
    echo '<div class="em-mb">';
    foreach([['Today',em_n($today)],['This Week',em_n($week)],['Last 30 Days',em_n($month)],['Unique (30d)',em_n($uniq)],['All Time',em_n($total)]] as[$l,$v])
        echo "<div class='em-mb-row'><span class='em-mb-lbl'>{$l}</span><span class='em-mb-val'>{$v}</span></div>";
    if($peak)echo "<div class='em-mb-row'><span class='em-mb-lbl'>Peak Day</span><span class='em-mb-val' style='font-size:11px'>".esc_html($peak['d'])." (".em_n((int)$peak['v']).")</span></div>";
    if($total===0)echo '<p style="font-size:11px;color:#6b6b80;margin:7px 0 0;text-align:center">No visits tracked yet</p>';
    echo '</div>';
}

// ── SPARKLINE COLUMN ─────────────────────────────────────────────────────────
function em_sparkline_column(array $cols):array{$cols['em_spark']='📈 30d';return $cols;}
function em_sparkline_column_data(string $col,int $pid):void{
    if($col!=='em_spark')return;
    global $wpdb;
    $data=$wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) AS d,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s GROUP BY d ORDER BY d ASC",$pid,em_from(em_days_ago(29))),ARRAY_A)?:[];
    $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE post_id=%d AND created_at>=%s",$pid,em_from(em_days_ago(29))));
    if(empty($data)){echo '<span style="color:#4a4a5a;font-size:11px">—</span>';return;}
    $vals=array_map('intval',array_column($data,'v'));$max=max($vals);
    $w=60;$h=22;$pts=count($vals);$step=$pts>1?$w/($pts-1):$w;
    $svg="<svg width='{$w}' height='{$h}' viewBox='0 0 {$w} {$h}' style='vertical-align:middle'>";
    $points='';
    foreach($vals as $i=>$v){$x=round($i*$step);$y=$max>0?round($h-($v/$max*($h-3))-2):$h/2;$points.="{$x},{$y} ";}
    $svg.="<polyline points='{$points}' fill='none' stroke='#c8a96e' stroke-width='1.5' stroke-linejoin='round' stroke-linecap='round'/></svg>";
    echo $svg.' <span style="font-family:\'DM Mono\',monospace;font-size:11px;color:#c8a96e;margin-left:3px">'.em_n($total).'</span>';
}

// ── EMAIL REPORT ─────────────────────────────────────────────────────────────
function em_send_report():void{
    $email=get_option('em_report_email',get_option('admin_email'));
    if(empty($email))return;
    global $wpdb;
    // Complete days only: yesterday, or the seven days up to yesterday.
    $daily=get_option('em_report_frequency','em_weekly')==='daily';
    $kind=$daily?'Daily':'Weekly';
    $s=em_days_ago($daily?1:7);$e=em_days_ago(1);
    $t=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS views,COUNT(DISTINCT visitor_hash) AS uniques,SUM(is_new_visitor) AS new_v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s",em_from($s),em_to($e)),ARRAY_A)?:['views'=>0,'uniques'=>0,'new_v'=>0];
    $top=$wpdb->get_results($wpdb->prepare("SELECT post_id,MAX(page_path) AS path,COUNT(*) AS v FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY post_id,IF(post_id=0,page_path,'') ORDER BY v DESC LIMIT 5",em_from($s),em_to($e)),ARRAY_A)?:[];
    $site=get_bloginfo('name');
    $rows='';
    foreach($top as $r){$pid=(int)$r['post_id'];[$ttl]=em_post_label($pid,(string)($r['path']??''));$rows.="<tr><td style='padding:8px 12px;border-bottom:1px solid #1e1e2e;color:#e8e8f0;font-size:12px'>".esc_html($ttl)."</td><td style='padding:8px 12px;border-bottom:1px solid #1e1e2e;color:#c8a96e;font-family:monospace;text-align:right'>".em_n((int)$r['v'])."</td></tr>";}
    $body="<div style='font-family:sans-serif;background:#0a0a0f;padding:28px;max-width:520px;margin:0 auto;border-radius:12px'><div style='margin-bottom:20px'><span style='font-size:18px'>📡</span><span style='font-size:16px;font-weight:600;color:#e8e8f0;margin-left:7px'>Exposure Meter</span><span style='font-size:11px;color:#6b6b80;display:block;margin-top:3px'>{$kind} Report — ".($s===$e?$s:"{$s} → {$e}")."</span></div><div style='display:flex;gap:10px;margin-bottom:20px'><div style='flex:1;background:#13131a;border:1px solid #1e1e2e;border-radius:8px;padding:14px;text-align:center'><div style='font-size:22px;font-weight:600;color:#e8e8f0'>".em_n((int)$t['views'])."</div><div style='font-size:9px;color:#6b6b80;text-transform:uppercase;letter-spacing:1px;margin-top:3px'>Page Views</div></div><div style='flex:1;background:#13131a;border:1px solid #1e1e2e;border-radius:8px;padding:14px;text-align:center'><div style='font-size:22px;font-weight:600;color:#e8e8f0'>".em_n((int)$t['uniques'])."</div><div style='font-size:9px;color:#6b6b80;text-transform:uppercase;letter-spacing:1px;margin-top:3px'>Unique Visitors</div></div><div style='flex:1;background:#13131a;border:1px solid #1e1e2e;border-radius:8px;padding:14px;text-align:center'><div style='font-size:22px;font-weight:600;color:#e8e8f0'>".em_n((int)$t['new_v'])."</div><div style='font-size:9px;color:#6b6b80;text-transform:uppercase;letter-spacing:1px;margin-top:3px'>New Visitors</div></div></div>".($rows?"<div style='margin-bottom:20px'><div style='font-size:9px;font-weight:600;color:#6b6b80;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px'>Top Content</div><table style='width:100%;border-collapse:collapse;background:#13131a;border-radius:8px;overflow:hidden'>{$rows}</table></div>":'')."<div style='text-align:center;padding-top:16px;border-top:1px solid #1e1e2e'><a href='".admin_url('admin.php?page=exposure-meter')."' style='color:#c8a96e;font-size:12px;text-decoration:none'>View Full Dashboard →</a></div></div>";
    wp_mail($email,"[{$site}] Exposure Meter — {$kind} Report",$body,['Content-Type: text/html; charset=UTF-8']);
}

// ── TRACKER ──────────────────────────────────────────────────────────────────
// Visits are counted in one of two ways (Settings > Cache-Compatible Mode):
// - off: when WordPress builds the page (template_redirect). Pages served from a page cache
//   (LiteSpeed Cache, WP Rocket…) never reach WordPress, so those visits are missed.
// - on: a small script in the page reports the visit (em_beacon, em_rest_ping), which also
//   works for cached pages.
function em_record_visit():void{
    if(get_option('em_cache_mode'))return;
    if(is_admin()||is_feed()||is_search()||is_404()||is_preview()||is_robots()||is_trackback())return;
    [$pid,$path]=em_current_page();
    em_store_visit($pid,$path,(string)($_SERVER['HTTP_REFERER']??''),em_parse_utm($_GET)); // phpcs:ignore
}
// The post or page being shown, or 0 with its address for anything else. Before 1.4.0 the
// id of a category, tag or author was stored as if it were a post's id.
function em_current_page():array{
    $pid=is_singular()?(int)get_queried_object_id():0;
    return[$pid,em_clean_path((string)($_SERVER['REQUEST_URI']??'/'))];
}
// The address without query string, readable (Persian slugs decoded), at most 255 characters.
function em_clean_path(string $uri):string{
    $p=explode('#',explode('?',$uri,2)[0],2)[0];
    $home=(string)parse_url(home_url('/'),PHP_URL_PATH);
    if($home!=='/'&&$home!==''&&str_starts_with($p,rtrim($home,'/')))$p=substr($p,strlen(rtrim($home,'/')));
    $p='/'.ltrim(preg_replace('#/+#','/',$p)?:'','/');
    $p=sanitize_text_field(rawurldecode($p));
    return mb_substr($p===''?'/':$p,0,255);
}
function em_store_visit(int $pid,string $path,string $ref,array $utm):bool{
    if(em_is_bot()||em_is_excluded_role())return false;
    global $wpdb;
    $ip=em_get_client_ip();$short=em_short_ip($ip);
    $ua=sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??''));
    $ref=sanitize_url(wp_unslash($ref));
    $hash=hash('sha256',$short.$ua.em_today().wp_salt('auth'));
    $dev=em_parse_device($ua);$geo=em_geo_lookup($ip);
    $rt=em_classify_referrer($ref);$new=em_is_new_visitor($hash);
    $now=current_time('mysql');
    $wpdb->insert($wpdb->prefix.'em_visits',array_merge(['post_id'=>$pid,'page_path'=>$path,'visitor_hash'=>$hash,'referrer'=>substr($ref,0,500),'referrer_type'=>$rt,'is_new_visitor'=>(int)$new,'created_at'=>$now],$dev,$geo,$utm));
    $title=$pid?(get_the_title($pid)?:get_bloginfo('name')):($path==='/'?get_bloginfo('name'):$path);
    $wpdb->replace($wpdb->prefix.'em_online',['visitor_hash'=>$hash,'post_id'=>$pid,'page_title'=>mb_substr($title,0,255),'country_code'=>$geo['country_code'],'device'=>$dev['device'],'referrer'=>substr($ref,0,500),'last_seen'=>$now]);
    return true;
}
// Cache-compatible mode: the page reports its own visit. The script holds no personal data;
// it sends the page's address, where the visitor came from, and any utm_ campaign tags.
function em_beacon():void{
    if(!get_option('em_cache_mode')||is_admin()||is_feed()||is_404()||is_preview()||is_robots())return;
    if(em_is_excluded_role())return; // pages built for logged-in users are not cached anyway
    [$pid]=em_current_page();
    $url=wp_json_encode(rest_url('exposure-meter/v1/ping'));
    echo "<script>(function(){try{var d=new FormData();d.append('post_id','".(int)$pid."');d.append('path',location.pathname);d.append('referrer',document.referrer||'');d.append('query',location.search||'');"
        ."if(navigator.sendBeacon){navigator.sendBeacon({$url},d);}else{fetch({$url},{method:'POST',body:d,keepalive:true,credentials:'same-origin'});}}catch(e){}})();</script>\n";
}
function em_is_bot():bool{
    if((defined('WP_CLI')&&WP_CLI)||(defined('DOING_CRON')&&DOING_CRON))return true;
    $ua=strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??'')));
    if(empty($ua))return true;
    foreach(['googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot','facebookexternalhit','twitterbot','linkedinbot','applebot','semrushbot','ahrefsbot','mj12bot','gptbot','claude-web','anthropic-ai','bytespider','petalbot','bot/','crawler','spider','scraper','curl/','wget/','python-requests','go-http-client','headlesschrome','phantomjs','pingdom','uptimerobot','feedfetcher','ia_archiver'] as $b)
        if(str_contains($ua,$b))return true;
    return false;
}
function em_is_excluded_role():bool{
    if(!is_user_logged_in())return false;
    return(bool)array_intersect((array)get_option('em_exclude_roles',['administrator','editor']),(array)wp_get_current_user()->roles);
}
function em_get_client_ip():string{
    foreach(['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $h)
        if(!empty($_SERVER[$h])){$ip=trim(explode(',',wp_unslash($_SERVER[$h]))[0]);if(filter_var($ip,FILTER_VALIDATE_IP))return $ip;}
    return '0.0.0.0';
}
// IP addresses are never stored. The address is shortened first: the last number of an IPv4
// address is set to 0, and of an IPv6 address only the network part (first 48 bits) is kept.
// Everything below works with the shortened form only.
function em_short_ip(string $ip):string{
    $bin=@inet_pton($ip);
    if($bin===false)return '';
    // An IPv4 address written the IPv6 way (::ffff:1.2.3.4) is treated as IPv4.
    if(strlen($bin)===16&&substr($bin,0,12)===str_repeat("\0",10)."\xff\xff")$bin=substr($bin,12);
    $bin=strlen($bin)===4?substr($bin,0,3)."\0":substr($bin,0,6).str_repeat("\0",10);
    return (string)inet_ntop($bin);
}
function em_geo_lookup(string $ip):array{
    $empty=['country_code'=>'','city'=>''];
    if(empty($ip)||$ip==='0.0.0.0')return $empty;
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return $empty;
    // Only the shortened address is sent to the lookup service and used for the cache.
    $short=em_short_ip($ip);
    if($short==='')return $empty;
    $key='em_geo_'.substr(hash_hmac('sha256',$short,wp_salt('auth')),0,32);$c=get_transient($key);if(is_array($c))return $c;
    $r=wp_remote_get('http://ip-api.com/json/'.rawurlencode($short).'?fields=countryCode,status',['timeout'=>3]);
    if(is_wp_error($r)){set_transient($key,$empty,HOUR_IN_SECONDS);return $empty;}
    $d=json_decode(wp_remote_retrieve_body($r),true);
    if(!is_array($d)||($d['status']??'')!=='success'){set_transient($key,$empty,HOUR_IN_SECONDS);return $empty;}
    $cc=strtoupper(sanitize_text_field($d['countryCode']??''));
    $res=['country_code'=>preg_match('/^[A-Z]{2}$/',$cc)?$cc:'','city'=>''];
    set_transient($key,$res,DAY_IN_SECONDS);return $res;
}
function em_parse_device(string $ua):array{
    $dev='desktop';
    if(preg_match('/tablet|ipad|playbook|silk/i',$ua))$dev='tablet';
    elseif(preg_match('/mobile|android|iphone|ipod|blackberry|opera mini/i',$ua))$dev='mobile';
    $br='Other';foreach(['Edg'=>'Edge','OPR'=>'Opera','Firefox'=>'Firefox','Chrome'=>'Chrome','Safari'=>'Safari'] as $p=>$n)if(str_contains($ua,$p)){$br=$n;break;}
    $os='Other';foreach(['Windows'=>'Windows','iPhone'=>'iOS','iPad'=>'iOS','Mac OS X'=>'macOS','Android'=>'Android','Linux'=>'Linux'] as $p=>$n)if(str_contains($ua,$p)){$os=$n;break;}
    return['device'=>$dev,'browser'=>$br,'os'=>$os];
}
function em_parse_utm(array $src):array{
    $r=[];foreach(['utm_source','utm_medium','utm_campaign','utm_term','utm_content'] as $p)$r[$p]=isset($src[$p])&&is_string($src[$p])?mb_substr(sanitize_text_field(wp_unslash($src[$p])),0,100):'';
    return $r;
}
function em_classify_referrer(string $ref):string{
    if(empty($ref))return 'direct';
    $h=strtolower((string)parse_url($ref,PHP_URL_HOST));
    if($h===strtolower((string)parse_url(home_url(),PHP_URL_HOST)))return 'internal';
    foreach(['google','bing','yahoo','duckduckgo','yandex','baidu'] as $e)if(str_contains($h,$e))return 'search';
    foreach(['facebook','instagram','twitter','x.com','linkedin','tiktok','youtube','reddit'] as $s)if(str_contains($h,$s))return 'social';
    return 'other';
}
function em_is_new_visitor(string $hash):bool{
    global $wpdb;
    return(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}em_visits WHERE visitor_hash=%s AND created_at>=%s",$hash,em_from(em_today())))===0;
}

// ── CRON ─────────────────────────────────────────────────────────────────────
function em_aggregate_daily_stats():void{
    global $wpdb;$y=em_days_ago(1);
    $rows=$wpdb->get_results($wpdb->prepare("SELECT post_id,COUNT(*) AS v,COUNT(DISTINCT visitor_hash) AS u FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s GROUP BY post_id",em_from($y),em_to($y)),ARRAY_A);
    foreach((array)$rows as $r)$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->prefix}em_aggregates(stat_date,post_id,views,uniques)VALUES(%s,%d,%d,%d) ON DUPLICATE KEY UPDATE views=VALUES(views),uniques=VALUES(uniques)",$y,(int)$r['post_id'],(int)$r['v'],(int)$r['u']));
}
function em_purge_old_data_rows():void{
    global $wpdb;$c=em_ago(max(30,(int)get_option('em_retention_days',365))*DAY_IN_SECONDS);
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}em_visits WHERE created_at<%s LIMIT 5000",$c));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}em_events WHERE created_at<%s LIMIT 5000",$c));
}
function em_purge_online_rows():void{
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}em_online WHERE last_seen<%s",em_ago(300)));
}

// ── REST ─────────────────────────────────────────────────────────────────────
function em_register_rest_routes():void{
    register_rest_route('exposure-meter/v1','/ping',['methods'=>'POST','callback'=>'em_rest_ping','permission_callback'=>'__return_true']);
    register_rest_route('exposure-meter/v1','/realtime',['methods'=>'GET','callback'=>'em_rest_realtime','permission_callback'=>fn()=>current_user_can('manage_options')]);
    register_rest_route('exposure-meter/v1','/overview',['methods'=>'GET','callback'=>'em_rest_overview','permission_callback'=>fn()=>current_user_can('manage_options')]);
}
function em_rest_ping(WP_REST_Request $req):WP_REST_Response{
    $done=fn(string $status)=>new WP_REST_Response(['status'=>$status],200);
    if(!get_option('em_cache_mode'))return $done('off'); // only counts when the mode is on
    if(em_is_bot())return $done('bot');
    // REST requests do not log the visitor in; check the login cookie so that excluded roles
    // (administrators, editors…) are not counted when they browse cached pages.
    if(defined('LOGGED_IN_COOKIE')&&!empty($_COOKIE[LOGGED_IN_COOKIE])){
        $uid=(int)wp_validate_auth_cookie((string)$_COOKIE[LOGGED_IN_COOKIE],'logged_in');
        if($uid){wp_set_current_user($uid);if(em_is_excluded_role())return $done('excluded');}
    }
    $pid=(int)$req->get_param('post_id');
    if($pid&&(!get_post($pid)||get_post_status($pid)!=='publish'))$pid=0;
    $path=em_clean_path((string)$req->get_param('path'));
    // One count per visitor and page every 30 seconds, so a script cannot inflate the numbers.
    global $wpdb;
    $ua=sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']??''));
    $hash=hash('sha256',em_short_ip(em_get_client_ip()).$ua.em_today().wp_salt('auth'));
    if($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}em_visits WHERE visitor_hash=%s AND page_path=%s AND created_at>=%s LIMIT 1",$hash,$path,em_ago(30))))return $done('repeat');
    parse_str(ltrim((string)$req->get_param('query'),'?'),$query);
    return $done(em_store_visit($pid,$path,(string)$req->get_param('referrer'),em_parse_utm($query))?'ok':'excluded');
}
function em_rest_realtime():WP_REST_Response{global $wpdb;em_purge_online_rows();$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}em_online ORDER BY last_seen DESC",ARRAY_A)?:[];return new WP_REST_Response(['count'=>count($rows),'visitors'=>$rows],200);}
function em_rest_overview(WP_REST_Request $req):WP_REST_Response{global $wpdb;$s=sanitize_text_field((string)$req->get_param('date_start'));if(!em_valid_day($s))$s=em_days_ago(29);$e=sanitize_text_field((string)$req->get_param('date_end'));if(!em_valid_day($e))$e=em_today();$t=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS views,COUNT(DISTINCT visitor_hash) AS uniques FROM {$wpdb->prefix}em_visits WHERE created_at>=%s AND created_at<%s",em_from($s),em_to($e)),ARRAY_A);return new WP_REST_Response(['totals'=>$t,'range'=>compact('s','e')],200);}
