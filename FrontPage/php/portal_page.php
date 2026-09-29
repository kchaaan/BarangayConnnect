<?php
declare(strict_types=1);
require_once __DIR__.'/supabase_client.php';
$user=bc_sb_current_user();
$role=(string)($_GET['role']??'');$path=(string)($_GET['path']??'');
if(!$user||!in_array($role,['admin','resident'],true)||($role==='admin'&&!bc_sb_role_has_admin_access($user['role']??null))||($role==='resident'&&($user['role']??'')!=='resident')){http_response_code($user?403:401);header('Content-Type: text/plain; charset=utf-8');echo $user?'Forbidden':'Authentication required';exit;}
if($path===''||str_contains($path,'..')||str_contains($path,"\\")||!preg_match("/^[A-Za-z0-9 _'().-]+\\.html$/",$path)){http_response_code(404);exit;}
$base=realpath(dirname(__DIR__,2).DIRECTORY_SEPARATOR.($role==='admin'?'Admin':'Residents').DIRECTORY_SEPARATOR.'html');
$file=realpath(($base?:'').DIRECTORY_SEPARATOR.$path);
if(!$base||!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');readfile($file);
