<?PHP
/* Copyright 2012-2023, Bergware International.
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
$plugin = "dynamix.file.integrity";

function expand_share($text) {
  return $text.'/';
}
function expand_folder($text) {
  $trim = trim($text);
  return ($trim[0]=='*' ? '' : '*').$trim.'/';
}
function expand_file($text) {
  $trim = trim($text);
  return ($trim[0]=='*' ? '' : '*').$trim.'$';
}
function regex($text) {
  return strtr($text,['.'=>'\.','['=>'\[',']'=>'\]','('=>'\(',')'=>'\)','{'=>'\{','}'=>'\}','+'=>'\+','-'=>'\-','*'=>'.*','&'=>'\&','?'=>'\?']);
}
$local  = "/usr/local/emhttp/plugins";
$boot   = "/boot/config";
$path   = "$boot/plugins/$plugin";
$docker = @parse_ini_file("$boot/docker.cfg");
$img    = 'DOCKER_IMAGE_FILE';
$new    = isset($default) ? array_replace_recursive($_POST, $default) : $_POST;
$bunker = "$local/$plugin/scripts/bunker";
$conf   = "/etc/inotifywait.conf";
$cron   = "$path/integrity-check.cron";
$run    = "$path/integrity-check.sh";
$tasks  = $map = [];
$cmd    = $new['cmd']??'';
$method = $new['method']??'';
$hash   = [''=>'sha256', '-md5'=>'md5', '-b2'=>'blake2', '-b3'=>'blake3'];
$hname  = $hash[$method];

if (isset($docker[$img]) && strpos(dirname($docker[$img]),'/mnt/disk')!==false) $map[] = expand_file(basename($docker[$img]));
if (isset($new['folders'])) $map = array_merge($map,array_map('expand_folder',explode(',',$new['folders'])));
if (isset($new['files']))   $map = array_merge($map,array_map('expand_file',explode(',',$new['files'])));
if (isset($new['exclude'])) $map = array_merge($map,array_map('expand_share',explode(',',$new['exclude'])));
if (isset($new['apple']))   $map = array_merge($map,[expand_folder('.AppleDB'),expand_file('.DS_Store')]);
if (count($map)>1)   {$open = '('; $close = ')';} else {$open = $close = '';}

$exclude = $map ? $open.regex(implode('|',$map)).$close : '';
$disks = str_replace(['disk',','],['/mnt/disk',' '],$new['disks']??'');

file_put_contents($conf, "cmd=\"$cmd\"\nmethod=\"$method\"\nexclude=\"$exclude\"\ndisks=\"$disks\"\n");
exec("$local/$plugin/scripts/rc.watcher ".(isset($new['service']) ? 'restart' : 'stop')." &>/dev/null");

foreach ($keys as $key => $value) {
  if ($key[0]!='#' && !array_key_exists($key,$new)) unset($keys[$key]);
}
foreach ($new as $key => $value) {
  if (preg_match('/^disk[0-9]/',$key)) {
    $do = explode('-',$key);
    $tasks[$do[1]][] = $do[0];
  }
}
$x = count($tasks);
if (isset($new['schedule']) && $new['schedule']>0 && $x>0) {
  $n = $new['notify']??'';
  $l = $new['log']??'';
  $m = $new['method']??'';
  switch ($new['schedule']) {
  case 1: //daily
    $time = "{$new['min']} {$new['hour']} * * *";
    $term = "\$((10#\$(date +%d)%$x))";
    break;
  case 2: //weekly
    $time = "{$new['min']} {$new['hour']} * * {$new['day']}";
    $term = "\$((10#$(date +%W)%$x))";
    break;
  case 3: //monthly
    $time = "{$new['min']} {$new['hour']} {$new['dotm']} * *";
    $term = "\$((10#\$(date +%m)%$x))";
    break;
  }
  if (isset($new['priority'])) {
    [$nice,$ionice] = array_pad(explode(',',$new['priority']),2,'');
    $bunker = "nice $nice ionice $ionice $bunker";
  }
  $i = 0;
  $text = [];
  $text[] = "#!/bin/bash";
  $text[] = "# This is an auto-generated file, do not change manually!";
  $text[] = "#";
  $mdcmd = file_exists('/proc/mdstat') ? 'mdstat':'mdcmd';
  if ($new['parity']) $text[] = "[[ \$(grep -Po '^mdResync=\K\S+' /proc/$mdcmd) -ne 0 ]] && exit 0";
  foreach ($tasks as $task) {
    if (empty($task)) continue;
    foreach ($task as $disk) {
      $log = strpos($l, '-f')!==false ? "$l $path/logs/$disk.export.\$(date +%Y%m%d).$hname.bad.log" : $l;
      $text[] = "[[ $term -eq $i ]] && $bunker -Vqj $m $n $log /mnt/$disk >/dev/null &";
    }
    $i++;
  }
  $text[] = "exit 0\n";
  file_put_contents($run, implode("\n",$text));
  file_put_contents($cron, "# Generated file integrity check schedule:\n$time bash $run &> /dev/null\n\n");
} else {
  @unlink($run);
  @unlink($cron);
}
exec("/usr/local/sbin/update_cron");
?>
