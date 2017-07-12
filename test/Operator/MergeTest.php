<?php
namespace Amp\Test\Operator;

use Amp\Producer;
use Amp\Deferred;
use Amp\Loop;
use Amp\InteractiveProducer;

class MergeTest extends \Amp\Test\Operator\OperatorTestCase {
	public function test_ready_values() {
		$left = new Producer(function($emitter) {
			$emitter(2);
			$emitter(4);
			$emitter(6);
			if(false) yield null;
		});
		$right = new Producer(function($emitter) {
			$emitter('foo');
			$emitter('bar');
			$emitter('baz');
			$emitter('quux');
			if(false) yield null;
		});
		$this->assertHotColdConsumersSeeValues(
			[2, 4, 6, 'foo', 'bar', 'baz', 'quux'],
			InteractiveProducer::merge([ $left, $right ])
		);
	}
	public function test_deferred_values() {
		$left = new Producer(function($emitter) {
			$deferred = new Deferred();
			Loop::defer(function() use ($emitter, $deferred) {
				$emitter(2);
				$deferred->resolve();
			});
			$emitter(4);
			$emitter(6);
			yield $deferred;
		});
		$right = new Producer(function($emitter) {
			$deferred = new Deferred();
			$emitter('foo');
			Loop::defer(function() use ($emitter, $deferred) {
				$emitter('bar');
				Loop::defer(function() use ($emitter, $deferred) {
					$emitter('quux');
					$deferred->resolve();
				});
			});
			$emitter('baz');
			yield $deferred;
		});
		$this->assertHotColdConsumersSeeValues(
			[2, 4, 6, 'foo', 'bar', 'baz', 'quux'],
			InteractiveProducer::merge([ $left, $right ])
		);
	}
}