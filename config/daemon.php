<?php

return array(

	'default' => array(

		// Maximum number of tasks that can be executed at the same time (parallel)
		'max' => 4,

		// Sleep time (in microseconds) when there's no active task / all processes are busy
		'sleep' => 1000000, // 1 second

		// save the PID file in this location
		'pid_path' => '/tmp/',

		// number of times a task should be tried before it is assumed failed
		'max_tries' => 1,

		// do you want to keep failed tasks in the queue? If you want to, you'll have to check for failed tasks yourself
		// and manage (delete/requeue) them manually!!! Don't let the queue fill up with failed tasks!!
		'keep_failed' => FALSE,

		// log types
		'log' => array(
			'error' => Log::ERROR,
			'debug' => Log::DEBUG
		)
	)

);