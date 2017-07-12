<?php

namespace Amp;

use Amp\Internal\Pointer;
use Amp\Internal\Queue;

final class Producer implements Iterator {
    use CallableMaker, Internal\Producer;

    /**
     * @param callable(callable(mixed $value): Promise $emit): \Generator $producer
     *
     * @throws \Error Thrown if the callable does not return a Generator.
     */
    public function __construct(callable $producer) {
        $this->buffer = new Queue([null]);
        $this->complete = new Pointer(null);
        $this->waiting = new Pointer(null);
        
        $result = $producer($this->callableFromInstanceMethod("emit"));

        if (!$result instanceof \Generator) {
            throw new \Error("The callable did not return a Generator");
        }

        $coroutine = new Coroutine($result);
        $coroutine->onResolve(function ($exception) {
            if ($this->complete->value) {
                return;
            }

            if ($exception) {
                $this->fail($exception);
                return;
            }

            $this->complete();
        });
    }
}
