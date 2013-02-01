<?php

/**
 * Git Collection
 *
 * @license http://www.gnu.org/licenses/lgpl-3.0.txt
 */

namespace Search\Collection\Git;

use GitWrapper\GitWrapper;
use Search\Framework\SearchCollectionAbstract;
use Search\Framework\SearchCollectionQueue;
use Search\Framework\SearchIndexDocument;

/**
 * A search collection for    Git logs and diffs.
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
     * Implements Search::Collection::SearchCollectionAbstract::init().
     *
     * Sets the GitWrapper object.
     */
    public function init()
    {
        $wrapper = $this->getOption('git_wrapper');

        if (!$wrapper instanceof GitWrapper) {

            $git_binary = $this->getOption('git_binary');
            $wrapper = new GitWrapper($git_binary);
        }

        $this->_wrapper = $wrapper;
    }

    /**
     * Implements Search::Collection::SearchCollectionAbstract::getQueue().
     */
    public function getQueue($limit = SearchCollectionQueue::NO_LIMIT)
    {

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
     * Implements Search::Collection::SearchCollectionAbstract::buildDocument().
     *
     * @param SearchIndexDocument $document
     *
     */
    public function buildDocument(SearchIndexDocument $document, $data)
    {

    }
}
