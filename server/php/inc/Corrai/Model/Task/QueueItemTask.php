<?php

namespace Corrai\Model\Task;

abstract class QueueItemTask
{
    public function process_task(object $queue_item_data): void
    {
        error_log('Processing task: ' . $queue_item_data->task_id);
        $this->process($queue_item_data);
        error_log('Task processed: ' . $queue_item_data->task_id);
    }
}
