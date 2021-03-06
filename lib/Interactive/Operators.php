<?php

namespace Amp\Interactive;

use Amp\InteractiveProducer;
use Amp\Success;
use Amp\Coroutine;
use Amp\Iterator;
use Amp\Promise;

trait Operators {
	/**
	 * [Combine multiple Producers into one by merging their emissions](http://reactivex.io/documentation/operators/merge.html)
	 * @param array<Iterator> $iterators
	 * @return Producer
	 */
	public static function merge(array $iterators): InteractiveProducer {
		return new self(function($_) use ($iterators) {
			return array_map(function(Iterator $iterator) {
				if($iterator instanceof BaseProducer)
					$iterator = clone $iterator;
				else
					return $iterator;
			}, $iterators);
		});
	}
	
	/**
	 * [Transform the items emitted by a Producer into Producers, then flatten the emissions from those into a single Producer](http://reactivex.io/documentation/operators/flatmap.html)
	 * 
	 * **Spec**:
	 *   - `Tv`-typed items from the return value might not preserve the order they are produced in _separate_ Producers created by `$coercer`.
	 * - **Preferred**:
	 *   - All ordering must be preserved.
	 * @param (function(T): InteractiveProducer<Tv>) $coercer - Transform `T`-valued items to Producers. E.g. for `T := Producer<Tv>`, $coercer may just be the identity function.
	 */
	public function flat_map(callable $coercer): InteractiveProducer {
		$clone = clone $this;
		return new self(function(callable $emitter) {
			return [new Coroutine((function() use ($emitter, $clone) {
				while(yield $clone->advance()) {
					$emitter((function() use ($emitter) {
						$subclone = clone $coercer($clone->getCurrent());
						while(yield $subclone->advance())
							$emitter($subclone->getCurrent());
					})());
				}
			})())];
		});
	}
	
	/**
	 * [Divide a Producer into a set of Producers that each emit a different subset of items from the original Producer.](http://reactivex.io/documentation/operators/groupby.html)
	 * 
	 * **Spec**:
	 * - Any items produced after the beginning call in the original Producer must be produced by exactly one of the `this`-typed Producers in the return value.
	 * @param <Tk as arraykey>(function(T): Tk) $keysmith - Assign `Tk`-valued keys to `T`-valued items
	 */
	public function group_by(callable $keysmith): InteractiveProducer {
		$subjects = [];
		$clone = clone $this;
		return self::create(new \Amp\Producer(function($emitter) use (&$subjects, $clone) {
			while(yield $clone->advance()) {
				$value = $clone->getCurrent();
				$key = $keysmith($value);
				if(!array_key_exists($key, $subjects)) {
					$subject = new Emitter();
					$subjects[$key] = $subject;
					$emitter($subject->iterate());
				}
				$subjects[$key]->emit($value);
			}
			foreach($subjects as $subject)
				$subject->complete();
		}));
	}
	
	/**
	 * [Only emit an item from a Producer if a particular timespan has passed without it emitting another item](http://reactivex.io/documentation/operators/debounce.html)
	 * 
	 * **Spec**
	 * - The last value of the original Producer, if there is one, must be produced in the return value.
	 * @param int $timespan - The "timespan" as described above, in milliseconds.
	 */
	public function debounce(int $timespan): InteractiveProducer {
		$clone = clone $this;
		return new self(function($outer_emitter) use ($timespan, $clone) {
			$counter = 0;
			$time_shifted_stream = new \Amp\Producer(function($inner_emitter) use (&$counter, $timespan, $clone) {
				while(yield $clone->advance())
					$inner_emitter(new Delayed($timespan, new class(++$counter, $clone->getCurrent()) {
						use \Amp\Struct;
						public $stashed_counter;
						public $payload;
						
						public function __construct($counter, $payload) {
							$this->stashed_counter = $counter;
							$this->payload = $payload;
						}
					}));
			});
			while(yield $time_shifted_stream->advance()) {
				$item = $time_shifted_stream->getCurrent();
				if($item->stashed_counter === $counter)
					$outer_emitter($item->payload);
			}
		});
	}
	
	/**
	 * [periodically subdivide items from a Producer into Producer windows and emit these windows rather than emitting the items one at a time](http://reactivex.io/documentation/operators/window.html)
	 * 
	 * Note: if the `$signal` ends prematurely (before the end of the source `Producer`), the items continue to be produced on the last window.
	 * @param \Amp\Iterator $signal - Produce a value whenever a new window opens.
	 * @return Producer Produce Producers that group values from the original into windows dictated by `$signal`.
	 */
	public function window(Iterator $signal): InteractiveProducer {
		$clone = clone $this;
		return new self(function($emitter) use ($clone, $signal) {
			$subject = new Emitter();
			$finished = false;
			return [
				(function() use (&$subject, $clone, &$finished) {
					while(yield $clone->advance())
						$subject->emit($clone->getCurrent());
					$finished = true;
					$subject->complete();
				})(),
				(function() use (&$subject, $emitter, $signal, &$finished) {
					while(yield $signal->advance()) {
						if($finished)
							return;
						
						$subject->complete();
						$subject = new Emitter();
						$emitter($subject->iterate());
					}
				})()
			];
		});
	}
	
	public static function never(): InteractiveProducer {
		return self::create(new Producer(function($_) {
			return (new Deferred)->promise();
		}));
	}
}