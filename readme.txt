=== Data Machine ===
Contributors: extrachill
Tags: ai, automation, workflow, agents, pipelines
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.174.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Agentic workflow automation for WordPress with pipelines, persistent agent memory, typed abilities, approvals, and durable background execution.

== Description ==

Data Machine turns WordPress into an agentic workflow runtime. Build reusable pipelines, configure and schedule flows, process work with AI, and track each execution as a durable job.

The plugin combines WordPress-native administration with persistent agent identity and memory, policy-gated tools, approval workflows, REST APIs, and WP-CLI commands. Data Machine builds on the bundled Agents API substrate and uses the bundled Action Scheduler library for background execution.

= Core features =

* **Pipelines, flows, and jobs** - Define reusable workflows, configure specific flow instances, and inspect execution state, retries, artifacts, and undo data.
* **Visual pipeline builder** - Create pipelines and configure their steps from a React-based WordPress admin interface.
* **Persistent agent memory** - Give agents scoped identity, instructions, durable memory, and daily history files.
* **Multi-agent operation** - Scope agents, pipelines, flows, jobs, and files to the appropriate WordPress user.
* **Provider-agnostic AI** - Use AI providers registered by compatible WordPress AI Client provider plugins.
* **Policy-gated tools and approvals** - Control which tools an agent can use and route sensitive operations through pending actions.
* **Durable background processing** - Run bounded cycles, scheduled flows, queue drains, retries, and workers through Action Scheduler.
* **Abilities, REST, and WP-CLI** - Automate Data Machine through typed WordPress abilities and purpose-built operator interfaces.
* **Portable agent bundles** - Export and import agent recipes containing memory, pipelines, flows, prompts, policies, and extension-owned extras.
* **WordPress content operations** - Fetch, publish, update, and analyze WordPress content and media.
* **Optional email workflows** - Fetch mail through IMAP and send mail through the site's configured WordPress mail transport.

= Workflow model =

A pipeline defines the reusable sequence of steps. A flow supplies handler configuration, scheduling, and agent ownership. Each execution creates jobs that preserve status, artifacts, retries, and observability.

Core step types include Fetch, AI, Publish, Upsert, Webhook Gate, and System Task. Core handlers support WordPress posts and media, remote WordPress REST sites, RSS, files, webhook payloads, email, typed artifacts, and WordPress publishing and updates.

Additional integrations are available through extensions. Social networks and Reddit are provided by Data Machine Socials; Google Sheets and business communication integrations are provided by Data Machine Business; workspace and GitHub operations are provided by Data Machine Code.

== Installation ==

1. Install and activate Data Machine.
2. Open **Data Machine > Settings**.
3. To use AI features, install a compatible WordPress AI Client provider plugin, configure its credentials, and select a default provider and model.
4. Open **Data Machine > Agent** to create or select an agent and configure its memory.
5. Open **Data Machine > Pipelines** to build a pipeline, create a flow, configure its handlers, and optionally schedule it.

Data Machine requires WordPress 7.0 or later and PHP 8.2 or later. Agents API and Action Scheduler are bundled with the plugin. Some optional features require the PHP IMAP extension, ZipArchive support, or writable WordPress upload directories.

== Frequently Asked Questions ==

= Do I need an AI provider? =

Only for AI-powered features. Pipeline steps that do not call an AI model can operate without an AI provider. AI features require a compatible WordPress AI Client provider plugin and credentials for that provider.

= Which AI providers are supported? =

Data Machine is provider-agnostic. Available providers and models come from the compatible WordPress AI Client provider plugins installed on your site. The provider plugin owns its credentials, endpoint, terms, and privacy policy.

= What is the difference between a pipeline and a flow? =

A pipeline defines the reusable structure of a workflow. A flow configures a pipeline for a specific agent, set of handlers, inputs, destinations, and schedule.

= Does Data Machine run work in the background? =

Yes. Data Machine uses the bundled Action Scheduler library for durable scheduled execution, retries, queue processing, and bounded worker cycles.

= Can operations require human approval? =

Yes. Tool policy can route sensitive operations into pending actions. Authorized users can inspect and resolve those actions before execution continues.

= Can extensions add integrations? =

Yes. Extensions can register handlers, abilities, tools, agent modes, bundle extras, and other integrations without modifying Data Machine core.

= Does Data Machine collect telemetry? =

No. Data Machine does not include telemetry or require a Data Machine-hosted SaaS connection. Network requests occur only when an administrator configures or invokes a feature that needs an external service, imports a remote bundle, or enables a scheduled workflow that uses one.

= Where is the documentation? =

Documentation and architecture references are maintained at https://github.com/Extra-Chill/data-machine/tree/main/docs.

== External Services ==

Data Machine can connect to external services when an administrator configures and invokes the associated feature. Scheduled workflows may repeat those configured requests automatically. The plugin does not make a mandatory connection to a Data Machine-hosted service.

= Configured AI providers =

For AI-powered steps and agent conversations, Data Machine sends the configured prompt, selected agent memory and context, relevant WordPress content, tool definitions and results, and model settings through the separately installed WordPress AI Client provider plugin.

The provider plugin determines the external vendor and endpoint. Review that provider plugin's service description, terms, and privacy policy before enabling it.

= User-selected HTTP resources =

Fetch handlers and tools can request administrator-supplied RSS or Atom feeds, remote WordPress REST API endpoints, web pages, files, and media URLs.

These requests send the site's server IP address and normal HTTP request metadata to the selected host. If the administrator configures authentication, the configured credentials are also sent to that host. The selected host's own terms and privacy policy apply.

= GitHub agent bundle imports =

When an administrator explicitly imports an agent bundle hosted on GitHub, Data Machine can contact github.com, raw.githubusercontent.com, or api.github.com. Requests include repository, path, and ref information and may include an administrator-configured GitHub token.

GitHub Terms of Service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service

GitHub Privacy Statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= External agents and webhooks =

Agent calls, Agent Pings, webhook steps, and callbacks can send administrator-configured tasks, content, context, callback information, and authentication data to operator-supplied URLs. The operator of each configured endpoint provides the applicable terms and privacy policy.

= Email services =

Email fetch operations connect to the administrator's selected IMAP server and send the configured mailbox credentials. Outgoing email uses WordPress `wp_mail()` and the site's configured mail transport. Email unsubscribe operations may contact a sender-provided URL or email address. The configured mail and endpoint providers' terms and privacy policies apply.

== Changelog ==

= 0.174.1 =

* Continued pre-1.0 reliability and release-readiness work across jobs, agents, email, bundles, tests, and packaging.
* See the complete release history at https://github.com/Extra-Chill/data-machine/releases.

== Upgrade Notice ==

= 0.174.1 =

This is a pre-1.0 release. Review the GitHub release notes and back up the site before upgrading a production installation.

== Links ==

* Documentation: https://github.com/Extra-Chill/data-machine/tree/main/docs
* Source: https://github.com/Extra-Chill/data-machine
* Issues and support: https://github.com/Extra-Chill/data-machine/issues
* Agents API: https://github.com/Automattic/agents-api
