<?php

namespace Amp\Internal;

use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use React\Promise\PromiseInterface as ReactPromise;

/**
 * Trait used by Iterator implementations. Do not use this trait in your code, instead compose your class from one of
 * the available classes implementing \Amp\Iterator.
 * Note that it is the responsibility of the user of this trait to ensure that listeners have a chance to listen first
 * before emitting values.
 *
 * @internal
 */
trait Producer {
    /** @var \Amp\Promise|null */
    private $complete;

    /**
    * Queue of values and backpressure promises
    * @var Queue
    */
    private $buffer;

    /** @var \Amp\Deferred|null */
    private $waiting;

    /** @var null|array */
    private $resolutionTrace;

    public function __clone() {
        $this->buffer = clone $this->buffer;
    }

    /**
     * {@inheritdoc}
     */
    public function advance(): Promise {
        if (!$this->buffer->is_empty()) {
            $buffer_item = $this->buffer->shift();
            $buffer_item->backpressure->resolve();
        }
        
        if(!$this->buffer->is_empty()) {
            return new Success(true);
        }
        
        if ($this->complete) {
            return $this->complete;
        }
        
        $this->waiting = $this->waiting ?? new Deferred;
        return $this->waiting->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrent() {
        if ($this->buffer->is_empty()) {
            if($this->complete)
                throw new \Error("The iterator has completed");
            else
                throw new \Error("Promise returned from advance() must resolve before calling this method");
        }

        return $this->buffer->shift();
    }

    /**
     * Emits a value from the iterator. The returned promise is resolved with the emitted value once all listeners
     * have been invoked.
     *
     * @param mixed $value
     *
     * @return \Amp\Promise
     *
     * @throws \Error If the iterator has completed.
     */
    private function emit($value): Promise {
        if ($this->complete) {
            throw new \Error("Iterators cannot emit values after calling complete");
        }

        if ($value instanceof ReactPromise) {
            $value = Promise\adapt($value);
        }

        if ($value instanceof Promise) {
            $deferred = new Deferred;
            $value->onResolve(function ($e, $v) use ($deferred) {
                if ($this->complete) {
                    $deferred->fail(
                        new \Error("The iterator was completed before the promise result could be emitted")
                    );
                    return;
                }

                if ($e) {
                    $this->fail($e);
                    $deferred->fail($e);
                    return;
                }

                $deferred->resolve($this->emit($v));
            });

            return $deferred->promise();
        }

        $pressure = new Deferred;
        $this->buffer->add(new class($value, $pressure) {
            use \Amp\Struct;
            public $value;
            public $backpressure;
            
            public function __construct($value, $backpressure) {
                $this->value = $value; $this->backpressure = $backpressure;
            }
        });

        if ($this->waiting !== null) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve(true);
        }

        return $pressure->promise();
    }

    /**
     * Completes the iterator.
     *
     * @throws \Error If the iterator has already been completed.
     */
    private function complete() {
        if ($this->complete) {
            $message = "Iterator has already been completed";

            if (isset($this->resolutionTrace)) {
                // @codeCoverageIgnoreStart
                $trace = formatStacktrace($this->resolutionTrace);
                $message .= ". Previous completion trace:\n\n{$trace}\n\n";
                // @codeCoverageIgnoreEnd
            } else {
                $message .= ", define const AMP_DEBUG = true and enable assertions for a stacktrace of the previous completion.";
            }

            throw new \Error($message);
        }

        \assert((function () {
            if (\defined("AMP_DEBUG") && \AMP_DEBUG) {
                // @codeCoverageIgnoreStart
                $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                \array_shift($trace); // remove current closure
                $this->resolutionTrace = $trace;
                // @codeCoverageIgnoreEnd
            }

            return true;
        })());

        $this->complete = new Success(false);

        if ($this->waiting !== null) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve($this->complete);
        }
    }

    private function fail(\Throwable $exception) {
        $this->complete = new Failure($exception);

        if ($this->waiting !== null) {
            $waiting = $this->waiting;
            $this->waiting = null;
            $waiting->resolve($this->complete);
        }
    }
}
