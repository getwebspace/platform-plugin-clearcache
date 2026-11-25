<?php declare(strict_types=1);

namespace Plugin\ClearCache\Tasks;

use App\Domain\AbstractTask;
use App\Domain\Casts\Task\Status as TaskStatus;
use App\Domain\Service\Task\TaskService;

class ClearCacheTask extends AbstractTask
{
    public const TITLE = 'Очистка кеша';

    protected function action(array $args = []): void
    {
        try {
            $this->logger->info('ClearCache: remove cache files');
            $removed = $this->purge(CACHE_DIR);

            if ($this->parameter('ClearCachePlugin_tasks', 'off') === 'on') {
                $this->logger->info('ClearCache: remove task data');
                $taskService = $this->container->get(TaskService::class);

                foreach (
                    $taskService->read([
                        'status' => [
                            TaskStatus::DONE,
                            TaskStatus::CANCEL,
                            TaskStatus::FAIL,
                            TaskStatus::DELETE,
                        ],
                    ]) as $model
                ) {
                    $taskService->delete($model);
                }
            }

            if ($this->parameter('ClearCachePlugin_log', 'off') === 'on') {
                $this->logger->info('ClearCache: remove log files');
                $this->purge(LOG_DIR);
            }

            if ($this->parameter('ClearCachePlugin_search', 'off') === 'on') {
                $this->logger->info('ClearCache: update search index');

                $task = new \App\Domain\Tasks\SearchIndexTask($this->container);
                $task->execute();
                \App\Domain\AbstractTask::worker($task);
            }

            $this->setStatusDone("Removed {$removed} cached file(s)");
        } catch (\Throwable $e) {
            $this->logger->warning('ClearCache: failed', ['error' => $e->getMessage()]);
            $this->setStatusFail($e->getMessage());
        }
    }

    /**
     * Recursively delete the *contents* of a directory, keeping the directory
     * itself. Dotfiles (`.gitkeep`, `.gitignore`, …) are left in place.
     *
     * Unlike the previous `exec('rm -rf ' . CONST . '/*')` this never shells
     * out and, crucially, bails out when the constant did not resolve to a
     * real, sufficiently deep path — a missing `var/cache` used to make
     * `realpath()` return `false`, turning the command into `rm -rf /*`.
     */
    private function purge(string|false $dir): int
    {
        if (!is_string($dir) || $dir === '' || !is_dir($dir) || ($real = realpath($dir)) === false) {
            $this->logger->warning('ClearCache: skip purge, directory not found', ['dir' => (string) $dir]);

            return 0;
        }

        if (substr_count($real, DIRECTORY_SEPARATOR) < 2) {
            $this->logger->warning('ClearCache: skip purge, path too shallow', ['dir' => $real]);

            return 0;
        }

        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if (str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            if ($item->isDir() && !$item->isLink()) {
                // a directory that still holds a dotfile (or a file written
                // meanwhile) is not empty: leave it, rmdir() would warn
                if ((new \FilesystemIterator($item->getPathname()))->valid() === false) {
                    @rmdir($item->getPathname());
                }
            } elseif (@unlink($item->getPathname())) {
                ++$count;
            }
        }

        return $count;
    }
}
