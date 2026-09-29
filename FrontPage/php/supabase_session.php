<?php
declare(strict_types=1); require_once __DIR__.'/supabase_client.php'; header('Content-Type: application/json; charset=utf-8');
try { $u=bc_sb_current_user(); if(!$u){http_response_code(401);echo json_encode(['authenticated'=>false]);exit;} echo json_encode(['authenticated'=>true,'role'=>$u['role'],'id'=>$u['user_id'],'fullName'=>$u['full_name'],'username'=>$u['username']??'','residentId'=>$u['resident']['resident_id']??'','resident'=>$u['resident']??null]); }
catch(Throwable $e){http_response_code(503);echo json_encode(['authenticated'=>false,'error'=>'Session service unavailable']);}
