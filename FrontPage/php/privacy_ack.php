<?php
declare(strict_types=1); require_once __DIR__.'/supabase_client.php'; header('Content-Type: application/json; charset=utf-8');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false]);exit;}
try{$u=bc_sb_require_role(['admin','resident']);$version=trim((string)($_POST['version']??''));if(!preg_match('/^[a-zA-Z0-9._-]{1,32}$/',$version)){http_response_code(400);echo json_encode(['success'=>false,'error'=>'Invalid notice version']);exit;}bc_sb_request('rest/v1/privacy_acknowledgments?on_conflict=user_id,notice_version','POST',['user_id'=>$u['user_id'],'notice_version'=>$version,'acknowledged'=>true,'acknowledged_at'=>gmdate('c')],null,true,['Prefer: resolution=merge-duplicates,return=minimal']);echo json_encode(['success'=>true]);}
catch(Throwable $e){error_log('Privacy acknowledgement failed: '.$e->getMessage());http_response_code(503);echo json_encode(['success'=>false,'error'=>'Could not save privacy acknowledgement']);}
