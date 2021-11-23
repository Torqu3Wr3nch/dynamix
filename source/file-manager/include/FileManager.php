<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2012-2021, Bergware International.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?
$action  = $_POST['action'];
$source  = explode("\n",urldecode($_POST['source']));
$target  = urldecode($_POST['target']);
$arg1    = preg_replace('/(["\'&()[\]\/])/','\\\\$1',$source[0]);
$running = '/tmp/file.manager.running';
$moving  = '/tmp/file.manager.moving';
$busy    = file_exists($running);
$idle    = !file_exists($moving);

function pgrep($proc) {
  global $arg1;
  return exec("pgrep -a $proc|awk '/$arg1/{print \$1;exit}'");
}
function truepath($file, $root="", $escape=false) {
  $file = preg_replace(['://+:',':\\+:'],'/',$file);
  $head = $file[0]=='/' ? '/' : '';
  $tail = $file[-1]=='/' ? '/' : '';
  $bits = array_filter(explode('/',$file),'strlen');
  $real = [];
  foreach ($bits as $bit) {
    if ($bit=='.') continue;
    if ($bit=='..') array_pop($real); else $real[] = $bit;
  }
  $real = $head.implode('/',$real).$tail;
  return $root ? (substr($real,0,strlen($root))==$root ? ($escape ? "\"$real\"" : $real) : "") : ($escape ? "\"$real\"" : $real);
}

function quotes($file) {return truepath($file,'/mnt/',true);}
function escape($file) {return is_array($file) ? implode(' ',array_map('quotes',$file)) : quotes($file);}

$reply = [];
$reply['status'] = 'working';
switch ($action) {
case 1: // delete folder
case 5: // delete file
  if ($busy) {
    $reply['status'] = 'working';
  } else {
    touch($running);
    exec("rm -rf ".escape($source)." >/dev/null 2>&1 &");
  }
  $reply['pid'] = pgrep('rm');
  break;
case 2: // rename folder
case 6: // rename file
  if ($busy) {
    $reply['status'] = 'working';
  } else {
    touch($running);
    $path = dirname($source[0]);
    exec("mv -f ".escape($source)." ".escape("$path/$target")." >/dev/null 2>&1 &");
  }
  $reply['pid'] = pgrep('mv');
  break;
case 3:  // copy folder
case 7:  // copy file
  if ($busy) {
    $reply['status'] = preg_replace('/\s\s+/',' ',rtrim(exec("tail -1 $running|grep -Pom1 '^.+ \K[0-9]+%[^(]+'"))) ?: 'working';
  } else {
    touch($running);
    exec("rsync -ahPIX --info=name0,progress2 ".escape($source)." ".escape($target).">$running 2>/dev/null &");
  }
  $reply['pid'] = pgrep('rsync');
  break;
case 4: // move folder
case 8: // move file
  if ($busy) {
    $reply['status'] = $idle ? 'moving' : (preg_replace('/\s\s+/',' ',rtrim(exec("tail -1 $running|grep -Pom1 '^.+ \K[0-9]+%[^(]+'"))) ?: 'working');
  } else {
    touch($running);
    touch($moving);
    $idle = false;
    exec("rsync -ahPIX --info=name0,progress2 ".escape($source)." ".escape($target).">$running 2>/dev/null &");
  }
  $reply['pid'] = $idle ? pgrep('rm') : pgrep('rsync');
  break;
case 99: // kill background processes
  foreach (['mv','rsync'] as $proc) exec("pkill -x $proc");
  $reply['pid'] = '';
  break;
}
if (empty($reply['pid'])) {
  if ($idle) {
    if (file_exists($running)) unlink($running);
    $reply['status'] = 'done';
  } else {
    unlink($moving);
    exec("rm -rf ".escape($source)." >/dev/null 2>&1 &");
    $reply['pid'] = 1;
  }
}
header('Content-Type: application/json');
die(json_encode($reply));
?>
