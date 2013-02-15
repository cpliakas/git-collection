<?php

/**
 * Git collection for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Collection\Git;

use GitWrapper\GitWrapper;
use Search\Framework\CollectionAbstract;
use \Search\Framework\CollectionAgentAbstract;
use Search\Framework\IndexDocument;
use Search\Framework\QueueMessage;

/**
 * A search collection for Git logs and diffs.
 */
class GitCollection extends CollectionAbstract
{

    protected $_type = 'git';

    protected static $_configBasename = 'git';

    /**
     * The feed being parsed.
     *
     * @var GitWrapper
     */
    protected $_wrapper;

    /**
     * An associative array of GitWorkingCopy objects keyed by
     *
     * @var array
     */
    protected $_workingCopies;

    /**
     * The repositories that the data is being collected from.
     *
     * @var array
     */
    protected $_repositories = array();

    /**
     * Implements CollectionAbstract::init().
     *
     * No-op.
     */
    public function init(array $options) {}

    /**
     * Sets the GitWrapper object.
     *
     * @return GitCollection
     */
    public function setGitWrapper(GitWrapper $wrapper)
    {
        $this->_wrapper = $wrapper;
        return $this;
    }

    /**
     * Returns the GitWrapper object.
     *
     * If a GitWrapper object is not set, one is instantiated with the defaults.
     *
     * @return GitWrapper
     */
    public function getGitWrapper()
    {
        if (!isset($this->_wrapper)) {
            $this->_wrapper = new GitWrapper();
        }
        return $this->_wrapper;
    }

    /**
     * Sets the directory that the repositories will be cloned to.
     *
     * @return GitCollection
     */
    public function setDataDir($data_dir)
    {
        $this->_dataDir = $data_dir;
        return $this;
    }

    /**
     * Attach a repository.
     *
     * @param string $repository
     *   The URL of the Git repository.
     *
     * @return GitCollection
     */
    public function attachRepository($repository)
    {
        $this->_repositories[] = $repository;
        return $this;
    }


    /**
     * Returns the data directory.
     *
     * If a data directory is not set, the "data" directory of this project's
     * root is set as the data directory.
     *
     * @return string
     */
    public function getDataDir()
    {
        if (!isset($this->_dataDir)) {
            $reflection = new \ReflectionClass($this);
            $class_dir = dirname($reflection->getFileName());
            $this->_dataDir = realpath($class_dir . '/../../../../data');
        }
        return $this->_dataDir;
    }

    /**
     * Returns the GitWorkingCopy object for a given repository.
     *
     * @param string $url
     *   The URL of the repository.
     *
     * @return \GitWrapper\GitWorkingCopy
     */
    public function getGit($repository)
    {
        if (!isset($this->_workingCopies[$repository])) {

            $name = GitWrapper::parseRepositoryName($repository);
            $directory = $this->getDataDir() . '/' . $name;
            $git = $this->getGitWrapper()->workingCopy($directory);

            if (!$git->isCloned()) {
                $git->clone($repository);
            } else {
                $git->pull()->clearOutput();
            }

            $this->_workingCopies[$repository] = $git;
        }
        return $this->_workingCopies[$repository];
    }

    /**
     * Implements CollectionAbstract::fetchScheduledItems().
     */
    public function fetchScheduledItems($limit = CollectionAgentAbstract::NO_LIMIT)
    {
        $items = array();
        foreach ($this->_repositories as $repository) {
            $git = $this->getGit($repository);

            $options = array();
            if ($limit != CollectionAgentAbstract::NO_LIMIT) {
                $options['n'] = $limit;
            }

            $log_messages = $git->log(null, null, $options);

            // Extract the commit hashes, suffix with the repository.
            preg_match_all('/commit\s+([a-f0-9]{40})/s', $log_messages, $matches);
            array_walk($matches[1], array($this, 'appendRepository'), $repository);

            $items = array_merge($items, $matches[1]);
        }

        return new \ArrayIterator($items);
    }

    /**
     * Array walk callback that appends the repository to the commit and
     * separates it by a colon.
     *
     * The resulting string is
     */
    public function appendRepository(&$commit, $key, $repository)
    {
        $commit .= ':' . $repository;
    }

    /**
     * Implements CollectionAbstract::buildQueueMessage().
     *
     * The item is the commit hash with the repository appended.
     */
    public function buildQueueMessage(QueueMessage $message, $item)
    {
        $message->setBody($item);
    }

    /**
     * Implements CollectionAbstract::loadSourceData().
     *
     * Executes a `git log -p [commit] -1` command to get the data, parses the
     * log entry into an associative array of parts.
     *
     * @return array
     */
    public function loadSourceData(QueueMessage $message)
    {
        $identifier = $message->getBody();
        list($commit, $repository) = explode(':', $identifier, 2);
        $git = $this->getGit($repository);

        $options = array(
            'p' => $commit,
            '1' => true,
        );
        $log = $git->log(null, null, $options);

        list($headers, $message, $diff) = explode("\n\n", $log);

        // Re-append a line break for lookahead pattern matching.
        $headers .= "\n";

        $data = array(
            'id' => $identifier,
            'commit' => $commit,
            'repository' => $repository,
            'message' => trim($message),
            'diff' => trim($diff),
        );

        $patterns = array(
            'author' => '/Author:\s+(.+)(?=\n)/s',
            'committer' => '/Committer:\s+(.+)(?=\n)/s',
            'date' => '/Date:\s+(.+)(?=\n)/s',
        );

        foreach ($patterns as $field_name => $pattern) {
            if (preg_match($pattern, $headers, $match)) {
                $data[$field_name] = $match[1];
            }
        }

        return $data;
    }

    /**
     * Implements CollectionAbstract::buildDocument().
     */
    public function buildDocument(IndexDocument $document, $data)
    {
        foreach ($data as $field_name => $field_value) {
            $document->$field_name = $field_value;
        }
    }
}
