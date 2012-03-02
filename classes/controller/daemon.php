<?php defined('SYSPATH') or die('No direct script access.');

/*
 * The task daemon. Reads queued items and executes them
 */

class Controller_Daemon extends Controller_CLI {

	/**
	 * Daemon name
	 */
	protected $_name;

	/**
	 * Configuration object
	 */
	protected $_config;

	/**
	 * Indicates if sigterm has been received
	 */
	protected $_sigterm;

	/**
	 * Stores forked PIDs
	 */
	protected $_pids = array();

	/**
	 * Controller::before - prepares daemon
	 */
	public function before()
	{
		parent::before();

		// Setup
		ini_set("max_execution_time", "0");
		ini_set("max_input_time", "0");
		set_time_limit(0);

		// Signal handler
		pcntl_signal(SIGCHLD, array($this, 'sig_handler'));
		pcntl_signal(SIGTERM, array($this, 'sig_handler'));
		declare(ticks = 1);

		// load config file
		$params        = $this->request->param();
		$this->_name   = $name = count($params) ? reset($params) : 'default';
		$this->_config = Kohana::$config->load('daemon')->$name;

		if ( empty($this->_config))
		{
			// configuration object not found - log & exit
			Kohana::$log->add(Log::ERROR, 'Queue. Config not found ("daemon.' . $name . '"). Exiting.');
			echo 'Queue. Config not found ("daemon.' . $name . '"). Exiting.' . PHP_EOL;
			exit;
		}

		$this->_config['pid_path'] = $this->_config['pid_path'] . 'MangoQueue.' . $name . '.pid';
	}

	/**
	 * Run daemon
	 *
	 * php index.php --uri=daemon
	 */
	public function action_index()
	{
		// fork into background
		$pid = $this->fork();

		if ( $pid == -1)
		{
			// Error - fork failed
			Kohana::$log->add($this->_config['log']['error'], 'MangoQueue. Initial fork failed');
			exit;
		}
		elseif ( $pid)
		{
			// Fork successful - exit parent (daemon continues in child)
			Kohana::$log->add($this->_config['log']['debug'], 'MangoQueue. Daemon created succesfully at: ' . $pid);

			// store PID in file
			file_put_contents( $this->_config['pid_path'], $pid);
			exit;
		}
		else
		{
			// Background process - run daemon

			Kohana::$log->add($this->_config['log']['debug'],strtr('Queue. Config :config loaded, max: :max, sleep: :sleep', array(
				':config' => $this->_name,
				':max'    => $this->_config['max'],
				':sleep'  => $this->_config['sleep']
			)));

			// Write the log to ensure no memory issues
			Kohana::$log->write();

			// run daemon
			$this->daemon();
		}
	}

	/*
	 * Exit daemon (if running)
	 *
	 * php index.php --uri=daemon/exit
	 */
	public function action_exit()
	{
		if ( file_exists( $this->_config['pid_path']))
		{
			$pid = file_get_contents($this->_config['pid_path']);

			if ( $pid !== 0)
			{
				Kohana::$log->add($this->_config['log']['debug'],'Sending SIGTERM to pid ' . $pid);
				echo 'Sending SIGTERM to pid ' . $pid . PHP_EOL;

				posix_kill($pid, SIGTERM);

				if ( posix_get_last_error() ===0)
				{
					echo "Signal send SIGTERM to pid ".$pid.PHP_EOL;
				}
				else
				{
					echo "An error occured while sending SIGTERM".PHP_EOL;
					unlink($this->_config['pid_path']);
				}
			}
			else
			{
				Kohana::$log->add($this->_config['log']['debug'], "Could not find MangoQueue pid in file :".$this->_config['pid_path']);
				echo "Could not find MangoQueue pid in file :".$this->_config['pid_path'].PHP_EOL;
			}
		}
		else
		{
			Kohana::$log->add($this->_config['log']['debug'], "MangoQueue pid file ".$this->_config['pid_path']." does not exist");
			echo "MangoQueue pid file ".$this->_config['pid_path']." does not exist".PHP_EOL;
		}
	}

	/*
	 * Get daemon & queue status
	 *
	 * php index.php --uri=daemon/status
	 */
	public function action_status()
	{
		$pid = file_exists($this->_config['pid_path'])
			? file_get_contents($this->_config['pid_path'])
			: FALSE;

		echo $pid
			? 'MangoQueue is running at PID: ' . $pid . PHP_EOL
			: 'MangoQueue is NOT running' . PHP_EOL;

		echo 'MangoQueue has ' . Mango::factory('task')->db()->count('tasks') . ' tasks in queue'.PHP_EOL;
	}

	/*
	 * This is the actual daemon process that reads queued items and executes them
	 */
	protected function daemon()
	{
		while ( ! $this->_sigterm)
		{
			if ( count($this->_pids) < $this->_config['max'])
			{
				// find next task
				$task = Mango::factory('task')->get_next();

				if ( $task->loaded())
				{
					if ( ! $task->valid())
					{
						// invalid tasks are discarded immediately
						Kohana::$log->add($this->_config['log']['error'], strtr('Discarded invalid task - uri :uri, request ":request"', array(
							':uri'     => $task->uri,
							':request' => $task->request
						)));

						$task->delete();
					}
					else
					{
						// Write log to prevent memory issues
						Kohana::$log->write();

						$pid = $this->fork();

						if ( $pid == -1)
						{
							Kohana::$log->add($this->_config['log']['error'], 'Queue. Could not spawn child task process.');
							exit;
						}
						elseif ( $pid)
						{
							// Parent - add the child's PID to the running list
							$this->_pids[$pid] = time();
						}
						else
						{
							$success = $task->execute($this->_config['max_tries']);

							if ( ! $success)
							{
								// if failed tasks are deleted, add request & response data to error message so it is logged
								$message = $this->_config['keep_failed']
									? $task->message
									: strtr($task->message . '\n :request \n :response', array(
											':request'  => $task->request()->render(),
											':response' => $task->response_data
										));

								Kohana::$log->add($this->_config['log']['error'], $message);
							}

							if ( ($task->status === 'completed' && ! $this->_config['keep_completed']) || ($task->status === 'failed' && ! $this->_config['keep_failed']))
							{
								// delete task
								$task->delete();
							}

							exit;
						}
					}
				}
				else
				{
					// queue is empty
					usleep($this->_config['sleep']);
				}
			}
			else
			{
				// daemon is busy
				usleep($this->_config['sleep']);
			}
		}

		// clean up
		$this->clean();
	}

	/**
	 * Performs clean up. Tries (several times if neccesary)
	 * to kill all children
	 */
	protected function clean()
	{
		$tries = 0;

		while ( $tries++ < 5 && count($this->_pids))
		{
			$this->kill_all();
			sleep(1);
		}

		if ( count($this->_pids))
		{
			Kohana::$log->add($this->_config['log']['error'],'Queue. Could not kill all children');
		}

		// Remove PID file
		unlink($this->_config['pid_path']);

		echo 'MangoQueue exited' . PHP_EOL;
	}

	/**
	 * Tries to kill all running children
	 */
	protected function kill_all()
	{
		foreach ($this->_pids as $pid => $time)
		{
			posix_kill($pid, SIGTERM);
			usleep(1000);
		}

		return count($this->_pids) === 0;
	}

	/**
	 * Signal handler. Handles kill & child died signal
	 */
	public function sig_handler($signo)
	{
		switch ($signo)
		{
			case SIGCHLD:
				// Child died signal
				while( ($pid = pcntl_wait($status, WNOHANG || WUNTRACED)) > 0)
				{
					// remove pid from list
					unset($this->_pids[$pid]);
				}
			break;
			case SIGTERM:
				// Kill signal
				$this->_sigterm = TRUE;
				Kohana::$log->add($this->_config['log']['debug'], 'Queue. Hit a SIGTERM');
			break;
		}
	}

	protected function fork()
	{
		// close all database connections before forking
		foreach ( MangoDB::$instances as $instance)
		{
			$instance->disconnect();
		}

		// Fork process to execute task
		return pcntl_fork();
	}
}