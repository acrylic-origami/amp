<?php

namespace Amp;

use Internal\Pointer;

final class InteractiveProducer implements Iterator {
    use CallableMaker, Internal\Producer;

    /**
     * @var Pointer<bool>
     */
    protected $running_count;
    
    /**
     * @var bool
     */
    protected $this_running = false;
    
    /**
     * @var Pointer<Pointer<bool>>
     */
    protected $some_running;

    /**
     * @param callable(callable(mixed $value): Promise $emit): array<Iterator | \Generator> $iterator_factory
     *
     * @throws \Error Thrown if the callable does not return a Generator.
     */
    public function __construct(callable $producer) {
        $this->queue = new Queue([null]);
        $this->running_count = new Pointer(0);
        $this->some_running = new Pointer(new Pointer(false));
        
        $iterators = $producer($this->callableFromInstanceMethod("emit"));
        
        if (!is_array($iterators)) {
            throw new \Error("The callable did not return an array");
        }
        
        $coroutines = [];
        foreach($iterators as $iterator) {
            if(!$iterator instanceof Iterator && !$iterator instanceof Generator)
                throw createTypeError([Iterator::class, \Generator::class], $iterator);
            
            $this->iterators[] = $iterator;
            if($iterator instanceof Iterator)
                $iterator = $this->iterator_to_emitting_generator($iterator, $emitter); // coerce to generator
            $coroutines[] = new Coroutine($iterator);
        }
        $lifetime = Promise\all($coroutines);
        $lifetime->onResolve(function ($exception) {
            if ($this->complete) {
                return;
            }

            if ($exception) {
                $this->fail($exception);
                return;
            }

            $this->complete();
        });
    }
    private function iterator_to_emitting_generator(Iterator $iterator, callable $emitter): \Generator {
        // `HHReactor\Producer::awaitify`'s Amp twin
        // eradicate all references to `$this` from the generator
        $stashed_some_running = $this->some_running->value;
        return (function() use ($stashed_some_running, $iterator, $emitter) {
            while(yield $iterator->advance()) {
                if(true === $stashed_some_running->value)
                    $emitter($iterator->getCurrent());
                else
                    yield $emitter($iterator->getCurrent());
            }
        })();
    }
}
