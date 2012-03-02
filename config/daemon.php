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

		// keep unsuccesfully executed tasks - make sure you check and delete them manually or the queue will clog up
		'keep_failed' => TRUE,

		// keep successfully executed tasks - make sure you check and delete them regularly or the queue will clog up
		'keep_completed' => FALSE,

		// log types
		'log' => array(
			'error' => Log::ERROR,
			'debug' => Log::DEBUG
		)
	)

);