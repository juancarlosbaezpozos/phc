<?php
/*
 * phc -- the open source PHP compiler
 * See doc/license/README.license for licensing information
 *
 * Allows a test to run programs asynchronously
 */

define ("MAX_RUNNING", 1);

function inst ($string)
{
#	print "\n" . microtime () . " $string\n";
}

class Async_steps
{
	function __construct ($object, $subject)
	{
		$this->object = $object;
		$this->subject = $subject;
	}

	function start ()
	{
		$this->object->start_program ($this->commands[0], $this, "continuation", 0);
	}

	function continuation ($proc_info, $state)
	{
#		print "continuing into state $state of {$this->subject}\n";
		$out =& $proc_info[0];
		$err =& $proc_info[1];
		$exit =& $proc_info[2];
		// state is just an int
		$this->outs[$state] =& $out;
		$this->errs[$state] =& $err;
		$this->exits[$state] =& $exit;

		$object = $this->object;

		if (isset ($this->out_handlers[$state]))
		{
			$handler = $this->out_handlers[$state];
			$out = $object->$handler ($out, $this);
		}

		if (isset ($this->err_handlers[$state]))
		{
			$handler = $this->err_handlers[$state];
			$result = $object->$handler ($err, $this);
			if ($result === false)
				return;
			$err = $result;
		}

		if (isset ($this->exit_handlers[$state]))
		{
			$handler = $this->err_handlers[$state];
			$result = $object->$handler ($exit, $this);
			if ($result === false)
				return;
			$exit = $result;
		}

		if (isset ($this->commands[$state+1]))
		{
			$command = $this->commands[$state+1];
			$object->start_program ($command, $this, "continuation", $state+1);
		}
		elseif (isset ($this->final))
		{
			$object->{$this->final} ($this);
		}
		else
		{
			die ("uhoh\n");
		}
	}
}

/* In order to take advantage of multiple cores, and to avoid
 * wasting a lot of time waiting for sequential programs to
 * run, we instead try to run programs in parallel.  Once it
 * has started the program, it will check if any programs
 * have finished execution, and if so, it will continue the
 * next part of their test.
 *
 * This also adds a nice abstraction level to the test suite.
 * Nearly all the tests can be described using a very simple
 * data description language, so this provides it.
 */
abstract class AsyncTest extends Test
{
	function mark_failure ($reason, $async)
	{
		parent::mark_failure (
					$async->subject, 
					$async->commands,
					$async->exits,
					$async->outs,
					$async->errs);

		return false;
	}

	# Add this program to the list of programs waiting to be
	# run. Start programs waiting to be run
	function start_program ($command, $object, $continuation, $state)
	{
		global $running_procs, $waiting_procs;
	
		# create a bundle
		$waiting_procs[] = array ($command, $object, $continuation, $state);
		$this->check_running_programs ();

	}


	function finish_test ()
	{
		global $running_procs;

		while (count ($running_procs))
		{
			$this->check_running_programs ();
		}
	}


	function check_running_programs ()
	{
		global $running_procs;

		// go through every runnig process and process it a bit more
		$num_procs = count ($running_procs);
		for ($i = 0; $i < $num_procs; $i++)
		{
			inst ("Poll running");
			$proc = array_shift ($running_procs);

			$handle =& $proc["handle"];
			$out =& $proc["out"];
			$err =& $proc["err"];
			$pipes =& $proc["pipes"];
			$command = $proc["command"];

#			print "Checking $command\n";

			$out .= stream_get_contents ($pipes[1]);
			$err .= stream_get_contents ($pipes[2]);

			$status = proc_get_status ($handle);

			if ($status["running"] !== true)
			{
				$exit_code = $status["exitcode"];
				$proc_info = array ($out, $err, $exit_code);
				// See Greenspun's Tenth Rule of Programming
				$continuation = $proc["continuation"];
				$state = $proc["state"];
				$object =& $proc["object"];

				inst ("Continuing $command checking proc $i");
				$object->$continuation ($proc_info, $state);
			}
			else
			{
				usleep (100000); // sleep for 1/20 of a second
				$running_procs[] = $proc;
			}
		}
		$this->check_waiting_procs ();
	}


	function check_waiting_procs ()
	{
		global $running_procs, $waiting_procs;

		while (count ($running_procs) < MAX_RUNNING)
		{
			inst ("Poll waiting");
			if (count ($waiting_procs) == 0)
			{
				inst ("No procs waiting");
				break;
			}

			$bundle = array_shift ($waiting_procs);
			$this->run_program ($bundle);
		}
	}

	function run_program ($bundle)
	{
		inst ("Running prog");
		global $running_procs;
		list ($command, $object, $continuation, $state) = $bundle;

#		print "starting program $command\n";
		// now start this process and add it to the list
		$descriptorspec = array(1 => array("pipe", "w"),
				2 => array("pipe", "w"));
		$pipes = array();
		$handle = proc_open($command, $descriptorspec, &$pipes);
		stream_set_blocking ($pipes[1], 0);
		stream_set_blocking ($pipes[2], 0);

		$proc = array(	"handle"			=> $handle,
				"pipes"			=> $pipes,
				"object"			=> $object,
				"command"		=> $command,
				"state"			=> $state,
				"continuation" => $continuation);

		$running_procs[] = $proc;
	}
}

?>
