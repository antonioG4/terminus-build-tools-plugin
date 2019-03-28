<?php

namespace Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\GitLabCI;

use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIProvider;
use Pantheon\TerminusBuildTools\ServiceProviders\CIProviders\CIState;
use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPI;
use Pantheon\TerminusBuildTools\API\GitLab\GitLabAPITrait;
use Pantheon\TerminusBuildTools\ServiceProviders\ProviderEnvironment;
use Pantheon\TerminusBuildTools\ServiceProviders\RepositoryProviders\GitLab\GitLabProvider;
use Pantheon\TerminusBuildTools\Task\Ssh\PrivateKeyReciever;
use Pantheon\TerminusBuildTools\Task\Ssh\PublicKeyReciever;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Pantheon\TerminusBuildTools\Credentials\CredentialClientInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialProviderInterface;
use Pantheon\TerminusBuildTools\Credentials\CredentialRequest;
use Robo\Common\ConfigAwareTrait;
use Robo\Config\Config;

/**
 * Manages the configuration of a project to be tested on GitLabCI.
 */
class GitLabCIProvider implements CIProvider, LoggerAwareInterface, PrivateKeyReciever, CredentialClientInterface
{
    use GitLabAPITrait;
    use LoggerAwareTrait;

    // We make this modifiable as individuals can self-host GitLab.
    protected $GITLAB_URL;
    protected $providerEnvironment;

    const SERVICE_NAME = 'gitlab-pipelines';

    protected $gitlab_token;
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->setGitLabUrl(GitLabAPI::determineGitLabUrl($config));
    }

    public function getEnvironment()
    {
        if (!$this->providerEnvironment) {
            $this->providerEnvironment = (new ProviderEnvironment())
            ->setServiceName(self::SERVICE_NAME);
        }
        return $this->providerEnvironment;
    }

    /**
     * @return array|mixed|null
     */
    public function getGitLabUrl() {
        return $this->GITLAB_URL;
    }

    /**
     * @param array|mixed|null $GITLAB_URL
     */
    public function setGitLabUrl($GITLAB_URL) {
        $this->GITLAB_URL = $GITLAB_URL;
    }

    public function infer($url)
    {
        return strpos($url, $this->getGitLabUrl()) !== false;
    }

    public function projectUrl(CIState $ci_env)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        return 'https://' . $this->getGitLabUrl() . '/' . $repositoryAttributes->projectId() . '/pipelines';
    }

    protected function apiUri(CIState $ci_env, $uri)
    {
        $repositoryAttributes = $ci_env->getState('repository');
        $apiRepositoryType = $repositoryAttributes->serviceName();
        $target_project = urlencode($repositoryAttributes->projectId());

        return "/api/v4/projects/$target_project/$uri";
    }

    /**
     * Return the text for the badge for this CI service.
     */
    public function badge(CIState $ci_env)
    {
        $url = $this->projectUrl($ci_env);
        return "[![GitLabCI]($url/badges/master/pipeline.svg)]($url)";
    }

    /**
     * Write the CI environment variables to the GitLabCI "envrionment variables" configuration section.
     *
     * @param CIState $ci_env
     * @param Session $session TEMPORARY to be removed
     */
    public function configureServer(CIState $ci_env)
    {
        $this->logger->notice('Configure GitLab CI');
        $this->setGitLabCIEnvironmentVars($ci_env);
    }

    protected function setGitLabCIEnvironmentVars(CIState $ci_env)
    {
        // Get the aggregation of all state variables that should be set
        // as environment variables. Also add the GitLab URL for future use.
        // This will cause CircleCI to set this environment varables during
        // test runs, which will automatically provide this value when we call:
        //   $config->get('build-tools.provider.git.gitlab.url')
        $vars = $ci_env->getAggregateState();
        $vars['TERMINUS_BUILD_TOOLS_PROVIDER_GIT_GITLAB_URL'] = $this->getGitLabUrl();

        return $this->setGitLabEnvVars($ci_env, $vars);
    }

    protected function setGitLabEnvVars(CIState $ci_env, $vars)
    {
        $this->deleteExistingVariables($ci_env, $vars);
        $uri = $this->apiUri($ci_env, 'variables');
        foreach ($vars as $key => $value) {
            // GitLab always obscures the variable values regardless of whether
            // they are protected or not. "Protected" variables are only
            // applied to protected branches (e.g. master).
            $protected = false;
            $data = ['key' => $key, 'value' => $value, 'protected' => $protected];
            if (empty($value)) {
                $this->logger->warning('Variable {key} empty: skipping.', ['key' => $key]);
            } else {
                $this->api()->request($uri, $data);
            }
        }
    }

    protected function deleteExistingVariables(CIState $ci_env, $vars)
    {
        $uri = $this->apiUri($ci_env, 'variables');
        $existing = $this->api()->request($uri);

        $intersecting = $this->findIntersecting($existing, $vars);

        foreach ($intersecting as $key) {
            $this->api()->request($uri . '/' . $key, [], 'DELETE');
        }
    }

    protected function findIntersecting($existing, $vars)
    {
        $intersecting = [];

        foreach ($existing as $row) {
            $key = $row['key'];
            if (array_key_exists($key, $vars)) {
                $intersecting[] = $key;
            }
        }
        return $intersecting;
    }


    public function startTesting(CIState $ci_env) {
        // Do nothing...it starts automatically.
    }

    public function addPrivateKey(CIState $ci_env, $privateKey)
    {
        // In GitLabCI, we must save the private key as an environment variable.
        $vars = ['SSH_PRIVATE_KEY' => file_get_contents($privateKey)];
        $this->setGitLabEnvVars($ci_env, $vars);
    }
}
