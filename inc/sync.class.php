<?php

class PluginRedminesyncSync extends CommonGLPI
{
    static $config = array();
    static $response_data = array();
    static $rightname = "plugin_redminesync";

    static function updateConfig($data)
    {
        global $DB;
        $value = serialize(array(
            'url' => $data['url'],
            'key' => $data['key'],
            'hour' => $data['hour']
        ));
        $DB->query("UPDATE glpi_configs SET value='$value' WHERE context='unotech' AND name='redmine_data'");
        $frequency = $data['hour'] * 60 * 60;
        $DB->query("UPDATE glpi_crontasks SET frequency='$frequency' WHERE itemtype='PluginRedminesyncSync' AND name='Syncredmine'");
        return true;
    }

    static function getConfig()
    {
        if (count(self::$config)) {
            return self::$config;
        } else {
            self::initConfig();
            return self::$config;
        }
    }

    static function initConfig()
    {
        global $DB;
        $result = $DB->query('SELECT * FROM glpi_configs WHERE context="unotech" AND name="redmine_data"');
        if ($result->num_rows == 0) {
            self::$config = array(
                'url' => '',
                'key' => '',
                'hour' => 24
            );
            $config = serialize(self::$config);
            $DB->query("INSERT INTO glpi_configs SET context='unotech', name='redmine_data', value='$config'");
        } else {
            $result = $DB->request("SELECT * FROM glpi_configs WHERE context='unotech' AND name='redmine_data'");
            foreach ($result as $value) {
                self::$config = unserialize($value['value']);
                return;
            }
        }
    }

    static function cronSyncredmine($task)
    {
        self::initConfig();
        self::syncProjects();
        self::syncTasks();
        return true;
    }

    // to sync projects
    static function syncProjects()
    {
        global $DB;
        if (self::$config['url'] == '' || self::$config['key'] == '') {
            return false;
        }
        $ch = curl_init();
        $request_url = self::$config['url'] . '/projects.json?key=' . self::$config['key'];
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $result = json_decode($response);

        // no projects
        if (NULL == $result || !count($result->projects)) {
            return true;
        }

        $project_ids = array();

        foreach ($result->projects as $value) {
            $project_ids[] = $value->id;
        }
        $project_ids_str = implode(', ', $project_ids);
        $res = $DB->request("SELECT rm_project_id FROM glpi_plugin_redminesync_synclog WHERE rm_project_id IN ($project_ids_str)");
        $inserted_ids = array();
        foreach ($res as $projects) {
            $inserted_ids[] = $projects['rm_project_id'];
        }

        foreach ($result->projects as $projects) {
            if (in_array($projects->id, $inserted_ids)) {
                self::updateProjects($projects);
            } else {
                self::addProjects($projects);
            }
        }
    }



    static function addProjects($data)
    {
        global $DB;

        $name = $data->name;
        $content = $data->description;
        $date_mod = date('Y-m-d H:i:s', strtotime($data->updated_on));
        $date_creation = date('Y-m-d H:i:s', strtotime($data->created_on));
        $redmine_id = $data->id;
        $now = date('Y-m-d H:i:s');

        $create_project_sql = "INSERT INTO glpi_projects SET priority='3', name='$name', content='$content', `date`='$date_creation', date_mod='$date_mod', date_creation='$date_creation', users_id='2'";
        $DB->query($create_project_sql);
        $project_id = self::getLastInsertedId();

        $add_history_sql = "INSERT INTO glpi_plugin_redminesync_synclog SET rm_project_id='$redmine_id', project_id='$project_id', task_id='0', rm_task_id='0', created_at='$now'";
        $DB->query($add_history_sql);
    }

    static function updateProjects($data)
    {
        global $DB;

        $name = $data->name;
        $content = $data->description;
        $redmine_id = $data->id;

        $inserted_project_id = $DB->request("SELECT project_id FROM glpi_plugin_redminesync_synclog WHERE rm_project_id=$redmine_id LIMIT 1");
        foreach ($inserted_project_id as $id) {
            $project_id = $id['project_id'];
        }

        $update_project_sql = "UPDATE glpi_projects SET name='$name', content='$content' WHERE id='$project_id'";
        $DB->query($update_project_sql);
    }

    // to sync projects
    static function syncTasks()
    {
        global $DB;
        if (self::$config['url'] == '' || self::$config['key'] == '') {
            return false;
        }
        $ch = curl_init();
        $request_url = self::$config['url'] . '/issues.json?key=' . self::$config['key'];
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $result = json_decode($response);

        // no issues
        if (NULL == $result || !count($result->issues)) {
            return true;
        }

        // check if already synced
        $issue_ids = array();
        foreach ($result->issues as $issue) {
            $issue_ids[] = $issue->id;
        }
        $issue_ids = implode(', ', $issue_ids);
        $res = $DB->request("SELECT rm_task_id FROM glpi_plugin_redminesync_synclog WHERE rm_task_id IN ($issue_ids)");
        $inserted_ids = array();
        foreach ($res as $issue) {
            $inserted_ids[] = $issue['rm_task_id'];
        }

        foreach ($result->issues as $issue) {
            if (in_array($issue->id, $inserted_ids)) {
                self::updateTasks($issue);
                self::addComments($issue);
            } else {
                self::addTasks($issue);
                self::addComments($issue);
            }
        }
    }

    static function addComments($issue)
    {
        global $DB;

        $ticket_id = self::getTicketIdByIssueId($issue->id);

        //URL for comments from Redmine
        $url = self::$config['url'] . '//issues/' . $issue->id . '.json?include=journals,attachments&key=' . self::$config['key'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response);

        if (isset($result->issue->journals)) {
            foreach ($result->issue->journals as $journal) {
                $notes = $journal->notes;
                if (!$notes == ''  && !self::checkIfNoteExistsInGlpi($journal->created_on)) {
                    $created_at = date('Y-m-d H:i:s', strtotime($journal->created_on));

                    //Create new massage
                    $add_message_sql = "INSERT INTO glpi_itilfollowups SET items_id='$ticket_id',itemtype ='Ticket', date_creation='$created_at', users_id='2', content='$notes', is_private='1'";
                    $DB->query($add_message_sql);
                }
            }
        }
        if (isset($result->issue->attachments)) {
            $redmine_files = [];
            foreach ($result->issue->attachments as $attachment) {
                //Check if file exists with given link
                if (self::checkIfFileExists($attachment->content_url)) {
                    $redmine_files[] = [
                        'filename' => $attachment->filename,
                        'link' => $attachment->content_url,
                        'mime' => $attachment->content_type,
                        'comment' => $attachment->description,
                        'date_creation' => date('Y-m-d H:i:s', strtotime($attachment->created_on))
                    ];
                }
            }
            self::mapFilesRedmineToGlpi($redmine_files, $ticket_id);
        }
    }

    // Function chechingk if File Exists
    static function checkIfFileExists($file_url)
    {
        $ch = curl_init($file_url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status == 200;
    }

    // Function mapping files from Redmine to GLPI
    static function mapFilesRedmineToGlpi($redmine_files, $ticket_id)
    {
        global $DB;

        // Check if file already exists in GLPI database
        $inserted_files = $DB->request("SELECT link FROM glpi_documents");
        $files = array();
        foreach ($inserted_files as $inserted_file) {
            $files[] = $inserted_file['link'];
        }

        foreach ($redmine_files as $redmine_file) {
            if (!in_array($redmine_file['link'], $files)) {

                $data = file_get_contents($redmine_file['link']);
                $hash = md5(uniqid(mt_rand(), true));

                //Filepath is a prototype version for tests, has to be written manually depending on the place on a server
                $localFilePath = '/var/www/html/glpi/files/TXT/20/' . $hash;

                file_put_contents($localFilePath, $data);

                $filepath = "TXT/20/" . $hash;

                $glpi_insert_query = "INSERT INTO glpi_documents SET name='',filepath='$filepath' ,filename ='{$redmine_file['filename']}', link='{$redmine_file['link']}',
            mime='{$redmine_file['mime']}',comment='{$redmine_file['comment']}',date_creation='{$redmine_file['date_creation']}', tickets_id='$ticket_id',users_id='2'";

                $DB->query($glpi_insert_query);

                $last_document_id = self::getLastInsertedId();

                $glpi_insert_item_query = "INSERT INTO glpi_documents_items (documents_id, items_id, itemtype, entities_id, is_recursive, date_creation, users_id, timeline_position)
            VALUES ('$last_document_id', '$ticket_id', 'Ticket', 1, 0, '{$redmine_file['date_creation']}', 2, 0)";
                $DB->query($glpi_insert_item_query);
            }
        }
    }
    static function getTicketIdByIssueId($redmine_issue_id)
    {
        global $DB;

        $sql = "SELECT task_id FROM glpi_plugin_redminesync_synclog WHERE rm_task_id = '$redmine_issue_id' LIMIT 1";
        $result = $DB->query($sql);
        if ($result && $DB->numrows($result) == 1) {
            foreach ($result as $res) {
                return $res['task_id'];
            }
        }
        return 0;
    }

    static function checkIfNoteExistsInGlpi($note_date_creation)
    {
        global $DB;
        $res = $DB->query("SELECT date_creation FROM glpi_itilfollowups");

        $notes_date = array();
        foreach ($res as $note_date) {
            $notes_date[] = $note_date['date_creation'];
        }
        $redmine_creation_date = date('Y-m-d H:i:s', strtotime($note_date_creation));

        if (in_array($redmine_creation_date, $notes_date))
            return true;
        else
            return false;
    }

    static function addTasks($issue)
    {
        global $DB;
        $name = $issue->subject;
        $content = $issue->description;
        $start_date = date('Y-m-d H:i:s', strtotime($issue->start_date));
        $due_date = date('Y-m-d H:i:s', strtotime($issue->due_date));
        $date_mod = date('Y-m-d H:i:s', strtotime($issue->updated_on));
        $date_creation = date('Y-m-d H:i:s', strtotime($issue->created_on));
        $actiontime = $issue->estimated_hours * 3600;
        $redmine_id = $issue->id;
        $redmine_project_id = $issue->project->id;
        $now = date('Y-m-d H:i:s');

        

        $res = $DB->request("SELECT project_id, rm_project_id FROM glpi_plugin_redminesync_synclog WHERE rm_project_id=$redmine_project_id LIMIT 1");
        $project_id = 0;
        if (!count($res)) {
            return false;
        }
        foreach ($res as $id) {
            $project_id = $id['project_id'];
        }

        $create_task_sql = "INSERT INTO glpi_projecttasks SET name='$name', content='$content', `date_creation`='$date_creation', date_mod='$date_mod', users_id='2', projects_id='$project_id'";
        $DB->query($create_task_sql);
        $task_id = self::getLastInsertedId();

        $projecttasks_id = $task_id;

        $tickets_ids = $DB->request("SELECT id FROM glpi_tickets");
        foreach ($tickets_ids as $ticket_id) {
            if ($ticket_id['id'] == $task_id) {
                $task_id++;
                $auto_increment_id = $task_id + 1;
                $DB->query("ALTER TABLE glpi_projecttasks AUTO_INCREMENT = '$auto_increment_id");
            }
        }
        if(isset($issue->due_date)){
            $create_ticket = "INSERT INTO glpi_tickets SET id='$task_id',closedate= '$due_date',date_mod='$date_mod', actiontime = '$actiontime', name='$name', `date`='$start_date',date_creation='$date_creation', content='$content'";
            $create_ticket_task = "INSERT INTO glpi_tickettasks SET tickets_id='$task_id',date='$date_creation',date_creation='$date_creation',begin='$start_date',end='$due_date',users_id='2',users_id_editor='2',content='$name',actiontime='$actiontime'";
        }
        else{
            $create_ticket = "INSERT INTO glpi_tickets SET id='$task_id',date_mod='$date_mod', actiontime = '$actiontime', name='$name', `date`='$start_date',date_creation='$date_creation', content='$content'";
            $create_ticket_task = "INSERT INTO glpi_tickettasks SET tickets_id='$task_id',date='$date_creation',date_creation='$date_creation',begin='$start_date',users_id='2',users_id_editor='2',content='$name',actiontime='$actiontime'";
        }
        $DB->query($create_ticket);
        $DB->query($create_ticket_task);

        $add_projecttickets_task = "INSERT INTO glpi_projecttasks_tickets SET tickets_id='$task_id', projecttasks_id = '$projecttasks_id'";
        $DB->query($add_projecttickets_task);

        $add_history_sql = "INSERT INTO glpi_plugin_redminesync_synclog SET rm_project_id='$redmine_project_id', project_id='$project_id', created_at='$now', task_id='$task_id', rm_task_id='$redmine_id'";
        $DB->query($add_history_sql);
    }

    static function updateTasks($data)
    {
        global $DB;

        $name = $data->subject;
        $content = $data->description;
        $redmine_id = $data->id;
        $date_creation = date('Y-m-d H:i:s', strtotime($data->created_on));

        $start_date = date('Y-m-d H:i:s', strtotime($data->start_date));
        $due_date = date('Y-m-d H:i:s', strtotime($data->due_date));
        $date_mod = date('Y-m-d H:i:s', strtotime($data->updated_on));

        $actiontime = $data->estimated_hours * 3600;


        $inserted_tasks_id = $DB->request("SELECT task_id FROM glpi_plugin_redminesync_synclog WHERE rm_task_id=$redmine_id LIMIT 1");
        foreach ($inserted_tasks_id as $id) {
            $task_id = $id['task_id'];
        }

        $update_task_sql = "UPDATE glpi_projecttasks SET name='$name', content='$content' WHERE id='$task_id'";
        $DB->query($update_task_sql);

        // Adding tickets in GLPI
        $DB->query("UPDATE glpi_tickets SET name='$name', content='$content' WHERE id='$task_id'");

        $tickets_dates = $DB->request("SELECT date FROM glpi_tickets");
        $inserted_ticket_dates = array();

        foreach ($tickets_dates as $date) {
            $inserted_ticket_dates[] = $date['date'];
        }
        if (!in_array($date_creation, $inserted_ticket_dates)) {
            if(isset($data->due_date))
                $create_ticket = "INSERT INTO glpi_tickets SET id='$task_id',closedate= '$due_date',date_mod='$date_mod', actiontime = '$actiontime', name='$name', `date`='$start_date',date_creation='$date_creation', content='$content'";
            else
                $create_ticket = "INSERT INTO glpi_tickets SET id='$task_id',date_mod='$date_mod', actiontime = '$actiontime', name='$name', `date`='$start_date',date_creation='$date_creation', content='$content'";

            $DB->query($create_ticket);
        }
    }

    static function getLastInsertedId()
    {
        global $DB;
        $inserted_id = $DB->query("SELECT LAST_INSERT_ID()");

        foreach ($inserted_id as $id) {
            $last_id = $id['LAST_INSERT_ID()'];
        }
        return $last_id;
    }
}
