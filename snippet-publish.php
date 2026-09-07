<?php
/**
 * knowhowseller.com/publish — TikTok 게시 도구 (Login Kit + Content Posting API)
 *
 * Code Snippets 에 붙여넣고 활성화한다. 아래 두 값만 채우면 된다.
 * 값은 TikTok 개발자 포털 > 앱 > Basic information 에서 확인.
 *
 * 리디렉션 URI 로 아래를 앱에 등록해야 한다:
 *   https://knowhowseller.com/publish?action=callback
 */

define('KS_TT_CLIENT_KEY',    'PUT_CLIENT_KEY_HERE');
define('KS_TT_CLIENT_SECRET', 'PUT_CLIENT_SECRET_HERE');
define('KS_TT_REDIRECT',      'https://knowhowseller.com/publish?action=callback');
define('KS_TT_SCOPE',         'user.info.basic,video.publish');
define('KS_TT_PAGE',          'https://raw.githubusercontent.com/knowhowseller/knowhowseller-portfolio/master/publish.html');

add_action('init', function () {
    $uri = untrailingslashit(strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
    if ($uri !== '/publish') return;

    // 운영자 본인만 쓰는 도구다. 로그인하지 않은 사람에게는 화면만 보이고 동작하지 않는다.
    $is_admin = current_user_can('manage_options');
    $action   = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

    // ── ① 연결 시작
    if ($action === 'connect') {
        if (!$is_admin) { wp_safe_redirect('/publish'); exit; }
        $state = wp_generate_password(24, false);
        set_transient('ks_tt_state', $state, 900);
        $url = 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query(array(
            'client_key'    => KS_TT_CLIENT_KEY,
            'scope'         => KS_TT_SCOPE,
            'response_type' => 'code',
            'redirect_uri'  => KS_TT_REDIRECT,
            'state'         => $state,
        ));
        wp_redirect($url); exit;
    }

    // ── ② 콜백: 코드 → 토큰
    if ($action === 'callback') {
        if (!$is_admin) { wp_safe_redirect('/publish'); exit; }
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        if (!$state || $state !== get_transient('ks_tt_state')) { wp_die('state 불일치'); }
        delete_transient('ks_tt_state');
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        if (!$code) { wp_die('code 없음'); }

        $r = wp_remote_post('https://open.tiktokapis.com/v2/oauth/token/', array(
            'timeout' => 20,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => array(
                'client_key'    => KS_TT_CLIENT_KEY,
                'client_secret' => KS_TT_CLIENT_SECRET,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => KS_TT_REDIRECT,
            ),
        ));
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (empty($j['access_token'])) { wp_die('토큰 발급 실패: ' . esc_html(wp_remote_retrieve_body($r))); }
        $j['obtained_at'] = time();
        update_option('ks_tt_token', $j, false);

        // 표시용 프로필
        $p = wp_remote_get('https://open.tiktokapis.com/v2/user/info/?fields=display_name,avatar_url', array(
            'timeout' => 15,
            'headers' => array('Authorization' => 'Bearer ' . $j['access_token']),
        ));
        $pj = json_decode(wp_remote_retrieve_body($p), true);
        update_option('ks_tt_user', $pj['data']['user'] ?? array(), false);
        wp_safe_redirect('/publish'); exit;
    }

    if ($action === 'disconnect') {
        if ($is_admin) { delete_option('ks_tt_token'); delete_option('ks_tt_user'); }
        wp_safe_redirect('/publish'); exit;
    }

    $tok = get_option('ks_tt_token');
    $access = is_array($tok) ? ($tok['access_token'] ?? '') : '';

    // ── ③ 게시: 파일을 받아 TikTok에 올린다
    if ($action === 'post') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$is_admin || !$access) { echo json_encode(array('error' => '연결이 필요합니다.')); exit; }
        if (empty($_FILES['video']['tmp_name'])) { echo json_encode(array('error' => '영상이 없습니다.')); exit; }

        $path = $_FILES['video']['tmp_name'];
        $size = (int) filesize($path);
        $cap  = isset($_POST['caption']) ? wp_strip_all_tags((string) $_POST['caption']) : '';

        // init — 한 조각으로 올린다(숏폼은 수 MB라 분할이 필요 없다)
        $init = wp_remote_post('https://open.tiktokapis.com/v2/post/publish/video/init/', array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $access,
                               'Content-Type'  => 'application/json; charset=UTF-8'),
            'body'    => wp_json_encode(array(
                'post_info' => array(
                    'title'                    => mb_substr($cap, 0, 2200),
                    'privacy_level'            => 'SELF_ONLY', // 심사 전에는 비공개만 허용된다
                    'disable_duet'             => false,
                    'disable_comment'          => false,
                    'disable_stitch'           => false,
                    'video_cover_timestamp_ms' => 1000,
                ),
                'source_info' => array(
                    'source'            => 'FILE_UPLOAD',
                    'video_size'        => $size,
                    'chunk_size'        => $size,
                    'total_chunk_count' => 1,
                ),
            )),
        ));
        $ij = json_decode(wp_remote_retrieve_body($init), true);
        $upload_url = $ij['data']['upload_url'] ?? '';
        $publish_id = $ij['data']['publish_id'] ?? '';
        if (!$upload_url) { echo json_encode(array('error' => 'init 실패: ' . wp_remote_retrieve_body($init))); exit; }

        // 바이트 업로드
        $bin = file_get_contents($path);
        $up  = wp_remote_request($upload_url, array(
            'method'  => 'PUT',
            'timeout' => 120,
            'headers' => array(
                'Content-Type'   => 'video/mp4',
                'Content-Length' => (string) $size,
                'Content-Range'  => 'bytes 0-' . ($size - 1) . '/' . $size,
            ),
            'body' => $bin,
        ));
        $code = wp_remote_retrieve_response_code($up);
        if ($code < 200 || $code >= 300) { echo json_encode(array('error' => '업로드 실패 HTTP ' . $code)); exit; }

        echo json_encode(array('publish_id' => $publish_id)); exit;
    }

    // ── ④ 상태 조회
    if ($action === 'status') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$is_admin || !$access) { echo json_encode(array('error' => '연결이 필요합니다.')); exit; }
        $pid = isset($_GET['publish_id']) ? sanitize_text_field($_GET['publish_id']) : '';
        $r = wp_remote_post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Bearer ' . $access,
                               'Content-Type'  => 'application/json; charset=UTF-8'),
            'body'    => wp_json_encode(array('publish_id' => $pid)),
        ));
        $j = json_decode(wp_remote_retrieve_body($r), true);
        echo json_encode(array('status' => $j['data']['status'] ?? wp_remote_retrieve_body($r))); exit;
    }

    // ── ⑤ 화면
    $key  = 'ks_publish_html';
    $html = get_transient($key);
    if ($html === false) {
        $r = wp_remote_get(KS_TT_PAGE, array('timeout' => 15));
        $html = is_wp_error($r) ? '' : wp_remote_retrieve_body($r);
        if ($html !== '') set_transient($key, $html, 600);
    }
    $user = get_option('ks_tt_user');
    $inject = ($access && is_array($user))
        ? '<script>window.KS_TIKTOK_USER=' . wp_json_encode(array(
              'name'   => $user['display_name'] ?? '연결됨',
              'avatar' => $user['avatar_url'] ?? '',
          )) . ';</script>'
        : '';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>영상 게시 도구 | 노하우셀러</title>'
       . '<meta name="robots" content="noindex">'
       . '<style>html,body{margin:0;background:#0f1114}</style></head><body>'
       . $inject . $html . '</body></html>';
    exit;
}, 0);
