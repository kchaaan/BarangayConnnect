<?php
declare(strict_types=1);
require_once __DIR__ . '/db_config.php';

function bc_sb_env(string $name, bool $required = true): string {
    $value = trim((string)getenv($name));
    if ($value === '' && $required) throw new RuntimeException('Missing server environment variable: '.$name);
    return $value;
}
function bc_sb_is_loopback_request(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\\d+$/', '', $host) ?? $host;
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)
        && in_array($remote, ['127.0.0.1', '::1'], true);
}
function bc_sb_url(string $path): string {
    return rtrim(bc_sb_env('SUPABASE_URL'), '/') . '/' . ltrim($path, '/');
}
function bc_sb_request(string $path, string $method='GET', array|string|null $body=null, ?string $accessToken=null, bool $service=true, array $extraHeaders=[]): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for Supabase.');
    $key = $service ? bc_sb_env('SUPABASE_SERVICE_ROLE_KEY') : bc_sb_env('SUPABASE_ANON_KEY');
    // New sb_publishable_ / sb_secret_ API keys are opaque keys, not JWTs.
    // Supabase requires them in `apikey`, while Authorization must contain a
    // real user JWT (when available). Legacy anon/service_role keys remain JWTs
    // and can still be used as the fallback bearer token.
    $headers = array_merge(['apikey: '.$key, 'Accept: application/json'], $extraHeaders);
    if ($accessToken !== null && $accessToken !== '') {
      $headers[] = 'Authorization: Bearer '.$accessToken;
    } elseif (!str_starts_with($key, 'sb_publishable_') && !str_starts_with($key, 'sb_secret_')) {
      $headers[] = 'Authorization: Bearer '.$key;
    }
    if ($body !== null && !array_filter($headers, static fn($h) => stripos($h, 'Content-Type:') === 0)) $headers[]='Content-Type: application/json';
    $ch=curl_init(bc_sb_url($path));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if ($body !== null) curl_setopt($ch,CURLOPT_POSTFIELDS,is_string($body)?$body:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw===false) throw new RuntimeException('Supabase request failed: '.$err);
    $data=$raw===''?null:json_decode($raw,true);
    if ($status<200 || $status>=300) {
      $message=is_array($data)?($data['msg']??$data['message']??$data['error_description']??$data['error']??'Supabase request rejected'):'Supabase request rejected';
      throw new RuntimeException((string)$message, $status);
    }
    return ['status'=>$status,'data'=>$data,'raw'=>$raw];
}
function bc_sb_rest(string $table, string $method='GET', array $query=[], ?array $body=null, array $prefer=[]): array {
    $allowed=['profiles','residents','document_requests','borrowing_records','transparency_records','emergency_contacts','id_requests','privacy_acknowledgments','announcements','resident_feedback','community_reports','resident_notifications','inventory_categories','inventory_items','borrow_requests','emergency_procedures','transparency_budget','transparency_budget_history','transparency_items','transparency_documents','chatbot_sessions','chatbot_messages','service_requests','service_request_files'];
    if (!in_array($table,$allowed,true)) throw new InvalidArgumentException('Unknown Supabase resource');
    $qs=http_build_query($query,'','&',PHP_QUERY_RFC3986); $headers=[];
    if ($prefer) $headers[]='Prefer: '.implode(',',$prefer);
    return bc_sb_request('rest/v1/'.$table.($qs?'?'.$qs:''),$method,$body,null,true,$headers);
}
function bc_sb_session_start(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
function bc_sb_current_user(bool $refresh=false): ?array {
    bc_sb_session_start(); $session=$_SESSION['bc_supabase']??null;
    if (!is_array($session) || empty($session['access_token'])) return null;
    if ($refresh || ((int)($session['expires_at']??0) < time()+30)) {
      if (empty($session['refresh_token'])) { unset($_SESSION['bc_supabase']); return null; }
      try {
        $r=bc_sb_request('auth/v1/token?grant_type=refresh_token','POST',['refresh_token'=>$session['refresh_token']],null,false);
        $_SESSION['bc_supabase']['access_token']=$r['data']['access_token'];
        $_SESSION['bc_supabase']['refresh_token']=$r['data']['refresh_token']??$session['refresh_token'];
        $_SESSION['bc_supabase']['expires_at']=time()+(int)($r['data']['expires_in']??3600);
      } catch(Throwable $e) { unset($_SESSION['bc_supabase']); return null; }
    }
    return $_SESSION['bc_supabase'];
}
function bc_sb_role_has_admin_access(?string $role): bool {
    return in_array($role, ['admin', 'superadmin'], true);
}
function bc_sb_require_role(array $roles): array {
    $u=bc_sb_current_user();
    $role = (string) ($u['role'] ?? '');
    $allowed = in_array($role, $roles, true)
        || (bc_sb_role_has_admin_access($role) && in_array('admin', $roles, true));
    if (!$u || !$allowed) { http_response_code($u?403:401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>$u?'Forbidden':'Authentication required']); exit; }
    return $u;
}
function bc_sb_verify_turnstile(string $response, string $remoteIp=''): bool {
    // The local-only token is for the PHP development server on loopback. It is
    // rejected for all non-local requests, where Cloudflare verification applies.
    if (bc_sb_is_loopback_request() && $response === 'LOCAL_TURNSTILE_TEST_TOKEN') return true;
    if ($response==='') return false;
    $secret=bc_sb_env('TURNSTILE_SECRET_KEY');
    $post=['secret'=>$secret,'response'=>$response]; if ($remoteIp!=='') $post['remoteip']=$remoteIp;
    $ch=curl_init(getenv('TURNSTILE_VERIFY_URL')?:'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); $result=is_string($raw)?json_decode($raw,true):null;
    return $status===200 && is_array($result) && ($result['success']??false)===true;
}
function bc_sb_storage_upload(string $bucket,string $objectPath,string $bytes,string $contentType): array {
    if (!preg_match('/^[a-z0-9-]{2,63}$/',$bucket) || str_contains($objectPath,'..') || str_starts_with($objectPath,'/')) throw new InvalidArgumentException('Invalid storage path');
    $path='storage/v1/object/'.rawurlencode($bucket).'/'.implode('/',array_map('rawurlencode',explode('/',$objectPath)));
    return bc_sb_request($path,'POST',$bytes,null,true,['Content-Type: '.$contentType,'x-upsert: false']);
}
function bc_sb_storage_signed_url(string $bucket,string $objectPath,int $expires=300): string {
    if (!preg_match('/^[a-z0-9-]{2,63}$/',$bucket) || str_contains($objectPath,'..')) throw new InvalidArgumentException('Invalid storage path');
    $r=bc_sb_request('storage/v1/object/sign/'.rawurlencode($bucket).'/'.implode('/',array_map('rawurlencode',explode('/',$objectPath))),'POST',['expiresIn'=>max(60,min(3600,$expires))]);
    $signed=$r['data']['signedURL']??''; return $signed!==''?bc_sb_url($signed):'';
}
