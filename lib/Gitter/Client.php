<?php

/*
 * This file is part of the Gitter library.
 *
 * (c) Klaus Silveira <klaussilveira@php.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gitter;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

class Client
{
    protected $path;
    protected $hidden;
    protected $env;

    public function __construct($options = null)
    {
        if (!isset($options['path'])) {
            $finder = new ExecutableFinder();
            $options['path'] = $finder->find('git', '/usr/bin/git');
        }
        $this->setPath($options['path']);
        $this->setHidden((isset($options['hidden'])) ? $options['hidden'] : array());
        if (!empty($options['env'])) {
            $this->env = $options['env'];
        }
    }

    /**
     * Creates a new repository on the specified path
     *
     * @param  string     $path Path where the new repository will be created
     * @return Repository Instance of Repository
     */
    public function createRepository($path, $bare = null)
    {
        if (file_exists($path . '/.git/HEAD') && !file_exists($path . '/HEAD')) {
            throw new \RuntimeException('A GIT repository already exists at ' . $path);
        }

        $repository = new Repository($path, $this);

        return $repository->create($bare);
    }

    /**
     * Opens a repository at the specified path
     *
     * @param  string     $path Path where the repository is located
     * @return Repository Instance of Repository
     */
    public function getRepository($path)
    {
        if (!file_exists($path) || !file_exists($path . '/.git/HEAD') && !file_exists($path . '/HEAD')) {
            throw new \RuntimeException('There is no GIT repository at ' . $path);
        }

        if (in_array($path, $this->getHidden())) {
            throw new \RuntimeException('You don\'t have access to this repository');
        }

        return new Repository($path, $this);
    }

    /**
     * Searches for valid repositories on the specified path
     *
     * @param  string $path Path where repositories will be searched
     * @return array  Found repositories, containing their name, path and description
     */
    public function getRepositories($path)
    {
        $repositories = $this->recurseDirectory($path);

        if (empty($repositories)) {
            throw new \RuntimeException('There are no GIT repositories in ' . $path);
        }

        sort($repositories);

        return $repositories;
    }

    /**
     * Clones a repository to a given path.
     *
     * @param string $url The URL of the repo to clone
     * @param string $path The file system path to which the repo should be cloned
     * @param array $options optional set of options to git.
     * @param array $args optional set of arguments to git.
     */
    public function cloneRepository($url, $directory, array $options = array(), array $args = array())
    {
        $repository = new Repository($directory, $this);
        array_unshift($args, $url, $directory);

        $this->run($repository, 'clone', $options, $args);
        return $repository;
    }

    private function recurseDirectory($path)
    {
        $dir = new \DirectoryIterator($path);

        $repositories = array();

        foreach ($dir as $file) {
            if ($file->isDot()) {
                continue;
            }

            if (strrpos($file->getFilename(), '.') === 0) {
                continue;
            }

            if ($file->isDir()) {
                $isBare = file_exists($file->getPathname() . '/HEAD');
                $isRepository = file_exists($file->getPathname() . '/.git/HEAD');

                if ($isRepository || $isBare) {
                    if (in_array($file->getPathname(), $this->getHidden())) {
                        continue;
                    }

                    if ($isBare) {
                        $description = $file->getPathname() . '/description';
                    } else {
                        $description = $file->getPathname() . '/.git/description';
                    }

                    if (file_exists($description)) {
                        $description = file_get_contents($description);
                    } else {
                        $description = 'There is no repository description file. Please, create one to remove this message.';
                    }

                    $repositories[] = array('name' => $file->getFilename(), 'path' => $file->getPathname(), 'description' => $description);
                    continue;
                } else {
                    $repositories = array_merge($repositories, $this->recurseDirectory($file->getPathname()));
                }
            }
        }

        return $repositories;
    }

    /**
     * Execute a git command on the repository being manipulated
     *
     * This method will start a new process on the current machine and
     * run git commands. Once the command has been run, the method will
     * return the command line output.
     *
     * @param Repository $repository Repository where the command will be run
     * @param string $command Git command to be run
     * @param array $options optional set of options to git.
     * @param array $args optional set of arguments to git.
     *
     * @return string Returns the command output
     */
    public function run($repository, $command, $options = array(), $args = array())
    {
        $prepared_command = $this->prepareCommand($command, $options, $args);
        $process = new Process($prepared_command, $repository->getPath());
        if (!empty($this->env)) {
            $process->setEnv($this->env);
        }
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * Gets the environment variables.
     *
     * @return array The current environment variables
     */
    public function getEnv()
    {
        return $this->env;
    }

    /**
     * Sets the environment variables.
     *
     * @param array $env The new environment variables
     *
     * @return self The current Process instance
     */
    public function setEnv(array $env)
    {
        $this->env = $env;

        return $this;
    }

     /**
     * Sets the passphrase that will be used with the private key when communicating over SSH.
     *
     * @param string $passphrase The password to use.
     */
    public function setSSHPassphrase($passphrase = NULL)
    {
        if (NULL == $passphrase) {
            unset($this->env['SSH_ASKPASS'], $this->env['DISPLAY'], $this->env['SSH_PASS']);
            return;
        }

        if (empty($this->env)) {
            $this->env = array();
        }

        $this->env['SSH_ASKPASS'] = __DIR__ . '/script/ssh-echopass';
        $this->env['DISPLAY'] = 'hack';
        $this->env['SSH_PASS'] = $passphrase;
    }

    /**
     * Get the current Git binary path
     *
     * @return string Path where the Git binary is located
     */
    protected function getPath()
    {
        return $this->path;
    }

    /**
     * Set the current Git binary path
     *
     * @param string $path Path where the Git binary is located
     */
    protected function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Get hidden repository list
     *
     * @return array List of repositories to hide
     */
    protected function getHidden()
    {
        return $this->hidden;
    }

    /**
     * Set the hidden repository list
     *
     * @param array $hidden List of repositories to hide
     */
    protected function setHidden($hidden)
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Prepares a command for execution. Prepends any Environment variables.
     *
     *
     * @param array $options optional set of options to git.
     * @param array $args optional set of arguments to git.
     *
     * @return string
     *  The prepared command.
     */
    protected function prepareCommand($command, array $options, array $args)
    {
        $command_parts = array(
          $this->getPath(),
          '-c "color.ui"=false',
          $command
        );

        if (count($options) > 0) {
            $options_items = array();
            foreach ($options as $name => $value) {
                $options_item = $name;
                if (!is_null($value)) {
                    $options_item .= ' ' . escapeshellarg($value);
                }
                $options_items[] = $options_item;
            }
            $command_parts[] = implode(' ', $options_items);
        }

        if (count($args) > 0) {
            $command_parts[] = implode(' ', array_map('escapeshellarg', $args));
        }

        return implode(' ', $command_parts);
    }
}
