<?php

namespace Corrai\Model\Task;

use Corrai\Utils\ObjectStore;

/**
 * Base class for tasks that process files from the object store.
 */
abstract class FileQueueItemTask
{
    public function process_task(object $queue_item_data): void
    {
        $s3_path = $queue_item_data->path ?? null;
        if ($s3_path === null || $s3_path === '') {
            error_log('S3 path is missing');
            return;
        }

        $store = ObjectStore::getInstance();
        if (!$store->exists($s3_path)) {
            error_log('S3 object not found: ' . $s3_path);
            return;
        }

        $file_content = $store->getContents($s3_path);
        if ($file_content === null) {
            error_log('File content is null');
            return;
        }
        $this->process($queue_item_data, $file_content);
    }

    protected abstract function process(object $queue_item_data, string $file_content): void;
}
