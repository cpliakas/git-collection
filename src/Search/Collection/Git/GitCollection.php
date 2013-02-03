<?php

/**
 * Git collection for the Search Framework library.
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Collection\Git;

use GitWrapper\GitWrapper;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchCollectionQueue;
use Search\Framework\SearchIndexDocument;
use Search\Framework\SearchIndexer;

/**
 * A search collection for Git logs and diffs.
 */
class GitCollection extends SearchCollectionAbstract
{

    protected static $_id = 'git';

    /**
     * This collection indexes data from git repositories.
     *
     * @var string
     */
    protected $_type = 'git';

    /**
     * The feed being parsed.
     *
     * @var GitWrapper
     */
    protected $_wrapper;

    /**
     * The repository that the data are being collected from.
     *
     * @var string
     */
    protected $_repository;

    /**
     * Implements Search::Collection::SearchCollectionAbstract::init().
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
            $data_dir = realpath($this->getClassDir() . '/../../../../data');
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
     * Implements Search::Collection::SearchCollectionAbstract::getQueue().
     */
    public function getQueue($limit = SearchIndexer::NO_LIMIT)
    {
        $name = GitWrapper::parseRepositoryName($this->_repository);
        $directory = $this->_dataDir . '/' . $name;
        $git = $this->_wrapper->workingCopy($directory);

        if (!$git->isCloned()) {
            $git->clone($this->_repository);
        } else {
            $git->pull()->clearOutput();
        }

        $options = array();
        if ($limit != SearchIndexer::NO_LIMIT) {
            $options['n'] = $limit;
        }

        $log = $git->log(null, null, $options);

        // Parse raw output into into an array of commits.
        $commits = array_filter(preg_split('/\n(?=commit [a-f0-9]{40})/s', $log));
        return new SearchCollectionQueue($commits);
    }

    /**
     * Implements Search::Collection::SearchCollectionAbstract::loadSourceData().
     *
     * Parses the line into an associative array of parts.
     *
     * @return array
     */
    public function loadSourceData($item)
    {
        $data = array();
        list($headers, $message) = explode("\n\n", $item);

        // Re-append a line break for lookahead pattern matching.
        $headers .= "\n";

        if (preg_match('/commit\s+([a-f0-9]{40})/s', $headers, $match)) {
            $data['commit'] = $match[1];
        }

        if (preg_match('/Author:\s+(.+)(?=\n)/s', $headers, $match)) {
            $data['author'] = $match[1];
        }

        if (preg_match('/Committer:\s+(.+)(?=\n)/s', $headers, $match)) {
            $data['committer'] = $match[1];
        }

        if (preg_match('/Date:\s+(.+)(?=\n)/s', $headers, $match)) {
            $data['date'] = strtotime($match[1]);
        }

        $data['message'] = trim($message);

        return $data;
    }

    /**
     * Implements Search::Collection::SearchCollectionAbstract::buildDocument().
     *
     * @param SearchIndexDocument $document
     *
     */
    public function buildDocument(SearchIndexDocument $document, $data)
    {
        $document->commit = $data['commit'];
        $document->author = $data['author'];
        $document->date = date('Y-m-d\TH:i:s\Z', $data['date']);
        $document->message = $data['message'];
    }
}
