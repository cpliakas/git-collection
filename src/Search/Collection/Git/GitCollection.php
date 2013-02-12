<?php

/**
 * Git collection for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Collection\Git;

use GitWrapper\GitWrapper;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchQueueMessage;
use Search\Framework\SearchIndexDocument;

/**
 * A search collection for Git logs and diffs.
 */
class GitCollection extends SearchCollectionAbstract
{

    protected $_type = 'git';

    protected static $_configBasename = 'git';

    protected static $_defaultLimit = 200;

    protected static $_defaultTimeout = 30;

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
     * The repository that the data is being collected from.
     *
     * @var string
     */
    protected $_repository;

    /**
     * Implements SearchCollectionAbstract::init().
     *
     * Sets the GitWrapper object.
     *
     * @throws \InvalidArgumentException
     */
    public function init()
    {
        $wrapper = $this->getOption('git_wrapper');
        if (!$wrapper instanceof GitWrapper) {
            $git_binary = $this->getOption('git_binary');
            $wrapper = new GitWrapper($git_binary);
        }

        $repository = $this->getOption('repository');
        if (!$repository) {
            $message = 'The "repository" option is required.';
            throw new \InvalidArgumentException($message);
        }

        $data_dir = $this->getOption('data_dir');
        if (!$data_dir) {
            $data_dir = realpath($this->_config->getRootDir($this) . '/data');
            if (!$data_dir) {
                $message = 'Data directory "' . $data_dir . '" could not be resolved.';
                throw new \InvalidArgumentException($message);
            }
        }

        $this->_wrapper = $wrapper;
        $this->_repository = $repository;
        $this->_dataDir = $data_dir;
    }

    /**
     * Returns the GitWrapper object.
     *
     * @return GitWrapper
     */
    public function getWrapper()
    {
        return $this->_wrapper;
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
            $directory = $this->_dataDir . '/' . $name;
            $git = $this->_wrapper->workingCopy($directory);

            if (!$git->isCloned()) {
                $git->clone($this->_repository);
            } else {
                $git->pull()->clearOutput();
            }

            $this->_workingCopies[$repository] = $git;
        }
        return $this->_workingCopies[$repository];
    }

    /**
     * Implements SearchCollectionAbstract::fetchScheduledItems().
     */
    public function fetchScheduledItems()
    {
        $git = $this->getGit($this->_repository);

        $options = array();
        $limit = $this->getLimit();
        if ($limit != self::NO_LIMIT) {
            $options['n'] = $limit;
        }

        $log = $git->log(null, null, $options);

        // Extract the commit hashes, suffix with the repository.
        preg_match_all('/commit\s+([a-f0-9]{40})/s', $log, $matches);
        array_walk($matches[1], array($this, 'appendRepository'), $this->_repository);

        return new \ArrayIterator($matches[1]);
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
     * Implements SearchCollectionAbstract::buildQueueMessage().
     *
     * The item is the commit hash with the repository appended.
     */
    public function buildQueueMessage(SearchQueueMessage $message, $item)
    {
        $message->setBody($item);
    }

    /**
     * Implements SearchCollectionAbstract::loadSourceData().
     *
     * Executes a `git log -p [commit] -1` command to get the data, parses the
     * log entry into an associative array of parts.
     *
     * @return array
     */
    public function loadSourceData(SearchQueueMessage $message)
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
     * Implements SearchCollectionAbstract::buildDocument().
     */
    public function buildDocument(SearchIndexDocument $document, $data)
    {
        foreach ($data as $field_name => $field_value) {
            $document->$field_name = $field_value;
        }
    }
}
