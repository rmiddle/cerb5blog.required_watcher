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
			case 'dao.ticket.update':
				$this->_workerOwned($event);
				break;
			case 'cerb5blog.context_link.watcher':
				$this->_workerWatched($event);
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
    
	private function _workerWatched($event) {
		$context = $event->params['context'];
 		
        switch($context) {
            case CerberusContexts::CONTEXT_TICKET:
                $this->_workerWatchedTicket($event);
                break;
            case CerberusContexts::CONTEXT_TASK:
                $this->_workerWatchedTask($event);
                break;
    	}	
    }

	private function _workerWatchedTicket($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

		$worker_id = $event->params['worker_id'];
		$context = $event->params['context'];
		$ticket_id = $event->params['context_id'];

        Context_Ticket::getContext($ticket_id, $token_labels, $values);
        
        $address = DAO_AddressOutgoing::getDefault();
        $default_from = $address->email;
        $default_personal = $address->reply_personal;

        // Sanitize and combine all the destination addresses
        $next_worker = DAO_Worker::get($worker_id);
        $to = $next_worker->email;

        if(empty($to))
            return;
        
        $params = array(
            'action' => 'relay_email',
			'to' => array( '0' => $to ),
			'subject' => "[Ticket Watcher #{{mask}}]: {{subject}}",
			'content' => 
"## Relayed from {{url}}
## Your reply to this message will be broadcast to the requesters. 
## Instructions: http://wiki.cerb5.com/wiki/Email_Relay
##
{{initial_message_content}}
",
			'include_attachments' => 1,
        );
        DevblocksEventHelper::runActionRelayEmail(
            $params,
            $values,
            CerberusContexts::CONTEXT_TICKET,
            $ticket_id,
            $values['group_id'],
            @$values['bucket_id'] or 0,
            $values['initial_message_id'],
            @$values['owner_id'] or 0,
            $values['initial_message_sender_address'],
            $values['initial_message_sender_full_name'],
            $values['subject']
        );
	}
    
	private function _workerWatchedTask($event) {
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
        
		$subject = sprintf("[Task Watcher #%d]: %s",
            $task->id,
			$task->title
        );
				
 		$url_writer = DevblocksPlatform::getUrlService();
        $url = $url_writer->write(sprintf("c=tasks&tab=display&id=%d", $task_id), true);

        $body = "\r\n## " . $url;
        $body .= "\r\nTitle: " . $task->title;
        $body .= "\r\nLast Update:  " . date("F j, Y, g:i a T", intval($task->updated_date));
        $body .= "\r\nDue Date: " . date("F j, Y, g:i a T", intval($task->due_date));
        $body .= "\r\nIs Completed: ";
        $body .= $task->is_completed ? "Closed" : "Open";
        if ($task->is_completed) {
            $body .= "\r\nCompleted Date: " . date("F j, Y, g:i a T", intval($task->completed_date));
        }

		CerberusMail::quickSend(
			$to,
			$subject,
			$body
		);
	}
    
	private function _workerOwned($event) {
		$translate = DevblocksPlatform::getTranslationService();
		$events = DevblocksPlatform::getEventService();

        $objects = $event->params['objects'];
        if(is_array($objects)) {
            foreach($objects as $object_id => $object) {
                @$model = $object['model'];
                @$changes = $object['changes'];
                    
                if(empty($model) || empty($changes))
                    continue;

                /*
                 * Owner changed
                 */
                if(isset($changes[DAO_Ticket::OWNER_ID])) {
                    @$owner = $changes[DAO_Ticket::OWNER_ID];
                        
                    if ( (!empty($owner['to'])) && ($owner['to'] !== 0) ) {
      					@$owner_id = $changes[DAO_Ticket::OWNER_ID]['to'];
                        @$ticket_id = $model[DAO_Ticket::ID];

                        Context_Ticket::getContext($ticket_id, $token_labels, $values);
                        
                        $address = DAO_AddressOutgoing::getDefault();
                        $default_from = $address->email;
                        $default_personal = $address->reply_personal;

                        // Sanitize and combine all the destination addresses
                        $next_worker = DAO_Worker::get($owner_id);
                        $to = $next_worker->email;
                            
                        if(empty($to))
                            return;
        
                        $params = array(
							'action' => 'relay_email',
							'to' => array( '0' => $to ),
							'subject' => "[Ticket Owner #{{mask}}]: {{subject}}",
							'content' => 
"## Relayed from {{url}}
## Your reply to this message will be broadcast to the requesters. 
## Instructions: http://wiki.cerb5.com/wiki/Email_Relay
##
{{initial_message_content}}
",
							'include_attachments' => 1,
						);
                        DevblocksEventHelper::runActionRelayEmail(
                            $params,
                            $values,
                            CerberusContexts::CONTEXT_TICKET,
                            $ticket_id,
                            $values['group_id'],
                            @$values['bucket_id'] or 0,
                            $values['initial_message_id'],
                            @$values['owner_id'] or 0,
                            $values['initial_message_sender_address'],
                            $values['initial_message_sender_full_name'],
                            $values['subject']
                        );
                    }
                }
            }
        }
	}
};

