<?php
require_once __DIR__.'/classes/ProcessQueue.php';
require_once __DIR__.'/classes/ProcessManager.php';

$queue = new ProcessQueue();
while($job = $queue->claimPending()){
  $start = microtime(true);
  $steps = array();
  try{
    ProcessManager::run($job['process_name'],$steps);
    $status='completed';
  }catch(Exception $e){
    $steps[] = $e->getMessage();
    $status='failed';
  }
  $duration = round(microtime(true)-$start,3);
  $steps[] = 'duration: '.$duration.'s';
  $queue->finish($job['id'],$status,implode("\n",$steps));
}
?>
