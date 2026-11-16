<?php

namespace MailcheckerTools\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Runs a hook method (e.g. afterCheckCompleted) on your model, on the queue. */
class RunHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param  class-string<Model>  $modelClass */
    public function __construct(
        public string $modelClass,
        public int|string $modelId,
        public string $hook,
    ) {}

    public function handle(): void
    {
        $model = $this->modelClass::query()->find($this->modelId);
        if ($model !== null && method_exists($model, $this->hook)) {
            $model->{$this->hook}();
        }
    }
}
