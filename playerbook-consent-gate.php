<?php
/*
Plugin Name: Playerbook Consent Gate (Register Redirect) — 1.4.3b
Description: Robust flow for BuddyX/Youzify. Minors are redirected to a consent page; after CF7 (non‑AJAX) submit we set a token cookie and redirect to the official Register page (prefilled & allowed). If user lands back on the consent page with a valid token, auto-redirect to Register to avoid loops. Consent persists on the user until deletion.
Version: 1.4.3b
Author: Playerbook + ChatGPT
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ===== Config =====
if ( ! defined('CP_MIN_AGE') )         define('CP_MIN_AGE', 18);
if ( ! defined('CP_XPROFILE_DOB_ID') ) define('CP_XPROFILE_DOB_ID', 17);     // BuddyPress xProfile "Date of Birth" field id
if ( ! defined('CP_CONSENT_PAGE_ID') ) define('CP_CONSENT_PAGE_ID', 2519);   // Consent page ID (hosts CF7 form)
if ( ! defined('CP_CF7_FORM_ID') )     define('CP_CF7_FORM_ID', 5149212);    // Contact Form 7 form ID
if ( ! defined('PB_CONSENT_DEBUG') )   define('PB_CONSENT_DEBUG', false);

if ( ! class_exists('PB_Consent_Gate_143b') ) :

final class PB_Consent_Gate_143b {

    public static function init() {
        // 0) Force CF7 to work without AJAX (classic POST)
        add_filter( 'wpcf7_load_js', '__return_false' );

        // 1) No-cache on consent page and on register when token exists
        add_action( 'template_redirect', array(__CLASS__, 'no_cache_rules'), 0 );

        // 1b) If on consent page and a valid token exists, auto-redirect to Register (avoids “same page reload”)
        add_action( 'template_redirect', array(__CLASS__, 'consent_page_auto_redirect_if_token'), 1 );

        // 2) HARD gate before signup is created (block minors without token)
        add_action( 'bp_before_register_page', array(__CLASS__, 'hard_gate_register'), 0 );
        add_action( 'template_redirect', array(__CLASS__, 'hard_gate_register'), 0 ); // fallback

        // 3) CF7 -> issue token + redirect to official register page (NOT the consent page)
        add_action( 'wpcf7_before_send_mail', array(__CLASS__, 'cf7_issue_token_and_redirect_to_register'), 1 );

        // 4) Prefill register form (email, DOB) when token is present
        add_action( 'wp_enqueue_scripts', array(__CLASS__, 'enqueue_register_prefill_js'), 99 );

        // 5) Persist consent on the user and clear token
        add_action( 'user_register', array(__CLASS__, 'attach_consent_to_user'), 10, 1 );

        // 6) Shortcode to render only the CF7 form on consent page
        add_shortcode( 'pb_consent_form', array(__CLASS__, 'shortcode_consent_form') );
    }

    // ===== Utilities =====
    private static function log( $msg, $data = null ) {
        if ( ! PB_CONSENT_DEBUG ) return;
        $prefix = '[PB Consent Gate] ';
        if ( is_array($data) || is_object($data) ) $msg .= ' ' . wp_json_encode( $data );
        error_log( $prefix . $msg );
    }
    private static function tz() {
        if ( function_exists('wp_timezone') ) return wp_timezone();
        $tz = get_option('timezone_string'); if ( ! $tz ) $tz = 'UTC'; return new DateTimeZone($tz);
    }
    private static function calc_age( $date_str ) {
        if ( empty($date_str) ) return null;
        try { $dob = new DateTime($date_str, self::tz()); $now = new DateTime('now', self::tz()); return (int) $now->diff($dob)->y; }
        catch ( Exception $e ) { return null; }
    }
    private static function parse_dob_from_post( $field_id ) {
        $k = 'field_' . (int) $field_id;
        if ( isset($_POST[$k]) && $_POST[$k] !== '' ) return sanitize_text_field( wp_unslash($_POST[$k]) );
        $y = isset($_POST[$k.'_year'])  ? (int) $_POST[$k.'_year']  : 0;
        $m = isset($_POST[$k.'_month']) ? (int) $_POST[$k.'_month'] : 0;
        $d = isset($_POST[$k.'_day'])   ? (int) $_POST[$k.'_day']   : 0;
        if ( $y && $m && $d ) return sprintf('%04d-%02d-%02d', $y, $m, $d);
        return '';
    }
    private static function get_email_from_request() {
        foreach ( array('signup_email','user_email','email','account_email','billing_email','child-email','child_email') as $k ) {
            if ( isset($_REQUEST[$k]) && $_REQUEST[$k] !== '' ) return sanitize_email( wp_unslash($_REQUEST[$k]) );
        }
        return '';
    }

    // ===== Token helpers =====
    private static function token_cookie_name() { return 'pb_consent_token'; }
    private static function token_key( $token ) { return 'pbct_' . preg_replace('/[^a-zA-Z0-9_\-]/','',$token); }
    private static function set_token_cookie( $token, $hours = 6 ) {
        $params = array(
            'expires'  => time() + $hours * HOUR_IN_SECONDS,
            'path'     => (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/',
            'domain'   => (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        setcookie( self::token_cookie_name(), $token, $params );
    }
    private static function clear_token_cookie() {
        @setcookie( self::token_cookie_name(), '', time()-3600, (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/', (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '', is_ssl(), true );
    }
    private static function get_token_cookie() {
        return isset($_COOKIE[ self::token_cookie_name() ]) ? sanitize_text_field($_COOKIE[ self::token_cookie_name() ]) : '';
    }

    private static function get_token_payload() {
        $token = self::get_token_cookie();
        if ( ! $token ) return null;
        $row = get_transient( self::token_key($token) );
        return is_array($row) ? $row : null;
    }

    // ===== No-cache rules =====
    public static function no_cache_rules() {
        $has_token = (bool) self::get_token_cookie();
        if ( is_page( CP_CONSENT_PAGE_ID ) || ( function_exists('bp_is_register_page') && bp_is_register_page() && $has_token ) ) {
            if ( ! defined('DONOTCACHEPAGE') )   define('DONOTCACHEPAGE', true);
            if ( ! defined('DONOTCACHEOBJECT') ) define('DONOTCACHEOBJECT', true);
            if ( ! defined('DONOTCACHEDB') )     define('DONOTCACHEDB', true);
            nocache_headers();
            @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            @header('Pragma: no-cache');
            @header('Expires: 0');
        }
    }

    // ===== Consent page auto-redirect if token exists =====
    public static function consent_page_auto_redirect_if_token() {
        if ( ! is_page( CP_CONSENT_PAGE_ID ) ) return;
        $row = self::get_token_payload();
        if ( ! $row || empty($row['child_email']) ) return;
        // Token is valid: go to official Register page
        $register_url = function_exists('bp_get_signup_page') ? bp_get_signup_page() : site_url('/register/');
        $register_url = add_query_arg( array( 'pbct' => time() ), $register_url );
        wp_safe_redirect( $register_url );
        exit;
    }

    // ===== Hard gate on Register =====
    public static function hard_gate_register() {
        if ( function_exists('bp_is_register_page') && bp_is_register_page() ) {
            if ( 'POST' !== strtoupper($_SERVER['REQUEST_METHOD']) ) return;
            $email = self::get_email_from_request();
            $dob   = self::parse_dob_from_post( CP_XPROFILE_DOB_ID );
            if ( ! $email || ! $dob ) return;
            $age = self::calc_age( $dob );
            if ( $age === null ) return;
            if ( $age < CP_MIN_AGE ) {
                $row    = self::get_token_payload();
                $ok     = is_array($row) && ! empty($row['child_email']) && strtolower($row['child_email']) === strtolower($email);
                if ( ! $ok ) {
                    wp_safe_redirect( get_permalink( CP_CONSENT_PAGE_ID ) );
                    exit;
                }
            }
        }
    }

    // ===== CF7: issue token + redirect to official Register page =====
    public static function cf7_issue_token_and_redirect_to_register( $contact_form ) {
        if ( ! is_object($contact_form) ) return;
        if ( (int) $contact_form->id() !== (int) CP_CF7_FORM_ID ) return;
        if ( ! class_exists('WPCF7_Submission') ) return;

        $submission = \WPCF7_Submission::get_instance();
        if ( ! $submission ) return;
        $posted = $submission->get_posted_data();

        $child_email    = isset($posted['child-email'])    ? sanitize_email($posted['child-email'])    : '';
        $guardian_email = isset($posted['guardian-email']) ? sanitize_email($posted['guardian-email']) : '';
        $minor_name     = isset($posted['minor-name'])     ? sanitize_text_field($posted['minor-name']) : '';
        $minor_dob      = isset($posted['minor-dob'])      ? sanitize_text_field($posted['minor-dob'])  : '';

        if ( empty($child_email) ) return;

        $token = 't_' . wp_generate_password(20, false, false);
        $payload = array(
            'child_email'       => $child_email,
            'guardian_email'    => $guardian_email,
            'minor_name'        => $minor_name,
            'minor_dob'         => $minor_dob,
            'when'              => current_time('mysql'),
            'ip'                => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
        );
        set_transient( self::token_key($token), $payload, 6 * HOUR_IN_SECONDS );
        self::set_token_cookie( $token, 6 );

        $register_url = function_exists('bp_get_signup_page') ? bp_get_signup_page() : site_url('/register/');
        $register_url = add_query_arg( array( 'pbct' => time() ), $register_url ); // cache-busting
        wp_safe_redirect( $register_url );
        exit;
    }

    // ===== Register page prefill (email & DOB) =====
    public static function enqueue_register_prefill_js() {
        if ( ! function_exists('bp_is_register_page') || ! bp_is_register_page() ) return;

        $row = self::get_token_payload();
        if ( ! is_array($row) || empty($row['child_email']) ) return;

        $handle = 'pb-register-prefill';
        wp_register_script( $handle, false, array(), null, true );
        $email   = $row['child_email'];
        $dob     = $row['minor_dob'];
        $fieldId = (int) CP_XPROFILE_DOB_ID;

        $js = "(function(){function setVal(s,v){var e=document.querySelector(s);if(!e)return;e.value=v;e.setAttribute('value',v);e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));try{e.setCustomValidity('');}catch(_){}}
function pad(n){n=parseInt(n,10);return(n<10?'0':'')+n;}
document.addEventListener('DOMContentLoaded',function(){
  var email=".wp_json_encode($email)."; if(email){var names=['signup_email','user_email','email','account_email','billing_email']; for(var i=0;i<names.length;i++){var el=document.querySelector('[name=\"'+names[i]+'\"]'); if(el){ setVal('[name=\"'+names[i]+'\"]', email); } } var confirms=['signup_email_confirm','signup_email-2','user_email-2','confirm_email','confirm_email_address','email_confirm','confirm-email']; for(var j=0;j<confirms.length;j++){ if(document.querySelector('[name=\"'+confirms[j]+'\"]')){ setVal('[name=\"'+confirms[j]+'\"]', email); } } }
  var dob=".wp_json_encode($dob)."; if(dob){var d=new Date(dob); if(!isNaN(d.getTime())){ var y=d.getFullYear(),m=d.getMonth()+1,day=d.getDate(); setVal('[name=\"field_".$fieldId."_year\"]', y); setVal('[name=\"field_".$fieldId."_month\"]', pad(m)); setVal('[name=\"field_".$fieldId."_day\"]', pad(day)); setVal('[name=\"field_".$fieldId+"\"]', y+'-'+pad(m)+'-'+pad(day)); } }
});})();";
        wp_add_inline_script( $handle, $js );
        wp_enqueue_script( $handle );
    }

    // ===== Persist consent on user and clear token =====
    public static function attach_consent_to_user( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $row = self::get_token_payload();
        if ( is_array($row) && ! empty($row['child_email']) && strtolower($row['child_email']) === strtolower($user->user_email) ) {
            update_user_meta( $user_id, 'cp_guardian_consent_ok', 1 );
            update_user_meta( $user_id, 'cp_guardian_consent_at', current_time('mysql') );

            if ( ! empty($row['guardian_email']) ) update_user_meta( $user_id, 'cp_guardian_email', sanitize_email($row['guardian_email']) );
            if ( ! empty($row['minor_name']) )     update_user_meta( $user_id, 'cp_minor_name', sanitize_text_field($row['minor_name']) );
            if ( ! empty($row['minor_dob']) )      update_user_meta( $user_id, 'cp_minor_dob', sanitize_text_field($row['minor_dob']) );
            update_user_meta( $user_id, 'cp_guardian_consent_ip', sanitize_text_field($row['ip'] ?? '') );
            update_user_meta( $user_id, 'cp_guardian_consent_when', sanitize_text_field($row['when'] ?? '') );
        }

        // Clear token & cookie
        $token = self::get_token_cookie();
        if ( $token ) delete_transient( self::token_key($token) );
        self::clear_token_cookie();
    }

    // ===== Shortcode to render only the CF7 form =====
    public static function shortcode_consent_form() {
        return do_shortcode( '[contact-form-7 id="' . (int) CP_CF7_FORM_ID . '" title="playerbook parental consent"]' );
    }
}

PB_Consent_Gate_143b::init();

endif;
