<?php
class Cerb5blogRequiredWatchersEventListener extends DevblocksEventListenerExtension {
	function __construct($manifest) {
		parent::__construct($manifest);
	}

	/**
	 * @param Model_DevblocksEvent $event
	 */
	function handleEvent(Model_DevblocksEvent $event) {
		switch($event->id) {
			case 'context_link.set':
				$this->_handleContextLink($event);
				break;
			case 'cerb5blog.context_link.watcher':
				$this->_workerAssigned($event);
				break;
		}
	}

	private function _handleContextLink($event) {
		$events = DevblocksPlatform::getEventService();
		
		// Assignment
		if(CerberusContexts::CONTEXT_WORKER == $event->params['to_context']) {
			// Trigger a context->worker assignment notification
			$events->trigger(
		        new Model_DevblocksEvent(
		            'cerb5blog.context_link.watcher',
	                array(
	                    'worker_id' => $event->params['to_context_id'],
	                    'context' => $event->params['from_context'],
	                    'context_id' => $event->params['from_context_id'],
	                )
	            )
			);
		}
	}
    
	private function _workerAssigned($event) {
		$context = $event->params['context'];
 		
        switch($context) {
            case CerberusContexts::CONTEXT_TICKET:
                $this->_workerAssignedTicket($event);
                break;
            case CerberusContexts::CONTEXT_TASK:
                $this->_workerAssignedTask($event);
                break;
    	}	
    }

	private function _workerAssignedTicket($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

		$worker_id = $event->params['worker_id'];
		$context = $event->params['context'];
		$ticket_id = $event->params['context_id'];

        $ticket = DAO_Ticket::get($ticket_id);
        
        $address = DAO_AddressOutgoing::getDefault();
        $default_from = $address->email;
        $default_personal = $address->reply_personal;

        // Sanitize and combine all the destination addresses
        $next_worker = DAO_Worker::get($worker_id);
        $to = $next_worker->email;

        if(empty($to))
            return;
        
        $ticket = DAO_Ticket::get($ticket_id);
        $messages = DAO_Message::getMessagesByTicket($ticket_id);			
		$message = end($messages); // last message
		unset($messages);

		$subject = sprintf("[Ticket Assignment #%s]: %s\r\n",
				$ticket->mask,
				$ticket->subject
        );
        
		$url_writer = DevblocksPlatform::getUrlService();
        $url = $url_writer->write(sprintf("c=display&mask=%s", $ticket->mask), true);

        $body = "## " . $url;
        $body .= "\r\n" . $message->getContent();

		CerberusMail::quickSend(
			$to,
			$subject,
			$body
		);				
	}
    
	private function _workerAssignedTask($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

		$worker_id = $event->params['worker_id'];
		$context = $event->params['context'];
		$task_id = $event->params['context_id'];
		
        $task = DAO_Task::get($task_id);
        
		// Sanitize and combine all the destination addresses
		$next_worker = DAO_Worker::get($worker_id);
        $to = $next_worker->email;
			
		if(empty($to))
			return;
			
		$subject = sprintf("[Task Assignment #%d]: %s",
            $task->id,
			$task->title
        );
				
        $body = sprintf("[Task Assignment #%d]: %s",
			$task->id,
			$task->title
		);
            
 		$url_writer = DevblocksPlatform::getUrlService();
        $url = $url_writer->write(sprintf("c=tasks&tab=display&id=%d", $task_id, true));

        $body = "\r\n## " . $url;
        $body .= "\r\nTitle: " . $task->title;
        $body .= "\r\nLast Update:  " . $task->updated_date;
        $body .= "\r\nDue Date: " . $task->due_date;
        $body .= "\r\nIs Completed: " . $task->is_completed ? "Open" : "Closed";
        $body .= "\r\nCompleted Date: " . $task->completed_date;

		CerberusMail::quickSend(
			$to,
			$subject,
			$body
		);				
	}
};

