#include "log.h"
#include "zbxalgo.h"
#include "zbxcommon.h"
#include "zbxhttp.h"
#include "zbxcacheconfig.h"
#include <event.h>
#include <event2/thread.h>
#include "poller.h"
#include "zbxserver.h"
#include "zbx_item_constants.h"
#include "zbxpreproc.h"
#include "zbxself.h"
#include "zbxnix.h"
#include "zbx_rtc_constants.h"
#include "zbxrtc.h"
#include "zbxtime.h"
#include "zbxtypes.h"
#include "httpagent_async.h"
#include "../../libs/zbxasyncpoller/asyncpoller.h"
#include "zbx_availability_constants.h"

typedef struct
{
	zbx_uint64_t	itemid;
	zbx_uint64_t	hostid;
	unsigned char	value_type;
	unsigned char	flags;
	unsigned char	state;
	char		*posts;
	char		*status_codes;
}
zbx_dc_item_context_t;

typedef struct
{
	zbx_poller_config_t	*poller_config;
	zbx_http_context_t	http_context;
	zbx_dc_item_context_t	item_context;
}
zbx_httpagent_context;

typedef enum
{
	ZABBIX_AGENT_STEP_CONNECT_WAIT = 0,
	ZABBIX_AGENT_STEP_SEND,
	ZABBIX_AGENT_STEP_RECV
}
zbx_zabbix_agent_step_t;
typedef struct
{
	zbx_poller_config_t	*poller_config;
	zbx_uint64_t		itemid;
	zbx_uint64_t		hostid;
	unsigned char		value_type;
	unsigned char		flags;
	unsigned char		state;
	char			*key;
	char			*key_orig;
	zbx_dc_host_t		host;
	zbx_dc_interface_t	interface;
	zbx_socket_t		s;
	zbx_zabbix_agent_step_t	step;
	char			*server_name;
	const char		*tls_arg1;
	const char		*tls_arg2;
	int			ret;
	AGENT_RESULT		result;
}
zbx_agent_context;
typedef struct
{
	zbx_dc_interface_t	interface;
	int			errcode;
	char			*error;
	zbx_uint64_t		itemid;
	char			host[ZBX_HOSTNAME_BUF_LEN];
	char			*key_orig;
}
zbx_interface_status;

static void	agent_context_clean(zbx_agent_context *agent_context)
{
	zbx_free(agent_context->key_orig);
	zbx_free(agent_context->key);
	zbx_free_agent_result(&agent_context->result);
}

static int	agent_task_process(short event, void *data)
{
	zbx_agent_context	*agent_context = (zbx_agent_context *)data;
	ssize_t			received_len;

	printf("chat_task_process(%x)\n", event);

	if (0 == event)
	{
		/* initialization */
		agent_context->step = ZABBIX_AGENT_STEP_CONNECT_WAIT;
		return ZBX_ASYNC_TASK_WRITE;
	}

	if (0 != (event & EV_TIMEOUT))
	{
		agent_context->ret = TIMEOUT_ERROR;
		SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent failed: timed out during %d", agent_context->step));
		return ZBX_ASYNC_TASK_STOP;
	}

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() step:%d event:%x", __func__, agent_context->step, event);

	switch (agent_context->step)
	{
		case ZABBIX_AGENT_STEP_CONNECT_WAIT:
			if (ZBX_TCP_SEC_TLS_CERT == agent_context->host.tls_connect ||
					ZBX_TCP_SEC_TLS_PSK == agent_context->host.tls_connect)
			{
				char	*error = NULL;
				short	event_tls = 0;

				if (SUCCEED != zbx_tls_connect(agent_context->s, agent_context->host.tls_connect,
						agent_context->tls_arg1, agent_context->tls_arg2,
						agent_context->server_name, &event_tls, &error))
				{
					if (POLLOUT & event_tls)
						return ZBX_ASYNC_TASK_READ;
					if (POLLIN & event_tls)
						return ZBX_ASYNC_TASK_WRITE;

					SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent"
						" failed: TCP successful, cannot establish TLS to [[%s]:%hu]: %s",
						agent_context->interface.addr, agent_context->interface.port, error));
					agent_context->ret = NETWORK_ERROR;
					//zbx_tcp_close(&agent_context->s);
					zbx_free(error);

					return ZBX_ASYNC_TASK_STOP;
				}
				else
				{
					agent_context->step = ZABBIX_AGENT_STEP_SEND;
					return ZBX_ASYNC_TASK_WRITE;
				}
			}
			ZBX_FALLTHROUGH;
		case ZABBIX_AGENT_STEP_SEND:
			if (0 == (event & EV_WRITE))
			{
				SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent failed:"
						" unexpected read event during send"));
				agent_context->ret = FAIL;
				return ZBX_ASYNC_TASK_STOP;
			}

			zabbix_log(LOG_LEVEL_DEBUG, "Sending [%s]", agent_context->key);

			if (SUCCEED != zbx_tcp_send(&agent_context->s, agent_context->key))
			{
				SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent failed: %s", zbx_socket_strerror()));
				agent_context->ret = NETWORK_ERROR;
				return ZBX_ASYNC_TASK_STOP;
			}
			else
			{
				agent_context->step = ZABBIX_AGENT_STEP_RECV;
				return ZBX_ASYNC_TASK_READ;
			}
			break;
		case ZABBIX_AGENT_STEP_RECV:
			if (0 == (event & EV_READ))
			{
				SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent failed:"
						" unexpected write event during send"));
				zabbix_log(LOG_LEVEL_DEBUG, "unexpected read event when reading result of [%s]", agent_context->key);
				return ZBX_ASYNC_TASK_STOP;
			}

			if (FAIL != (received_len = zbx_tcp_recv_ext(&agent_context->s, 0, 0)))
			{
				zbx_agent_handle_response(&agent_context->s, received_len, &agent_context->ret,
						agent_context->interface.addr, &agent_context->result);
				zabbix_log(LOG_LEVEL_DEBUG, "received");
				agent_context->ret = SUCCEED;
				return ZBX_ASYNC_TASK_STOP;
			}
			else
			{
				SET_MSG_RESULT(&agent_context->result, zbx_dsprintf(NULL, "Get value from agent failed: %s", zbx_socket_strerror()));
				agent_context->ret = NETWORK_ERROR;
			}
			break;
	}

	return ZBX_ASYNC_TASK_STOP;
}

static void	agent_task_free(void *data)
{
	zbx_agent_context	*agent_context = (zbx_agent_context *)data;
	zbx_timespec_t		timespec;
	zbx_interface_status	*interface_status;
	int			ret;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() host:'%s' addr:'%s' key:'%s' conn:'%s'", __func__, agent_context->host.host,
			agent_context->interface.addr, agent_context->key,
			zbx_tcp_connection_type_name(agent_context->host.tls_connect));

	zbx_timespec(&timespec);

	/* don't try activating interface if there were no errors detected */
	if (SUCCEED != agent_context->ret || ZBX_INTERFACE_AVAILABLE_TRUE != agent_context->interface.available ||
			0 != agent_context->interface.errors_from)
	{
		if (NULL == (interface_status = zbx_hashset_search(&agent_context->poller_config->interfaces,
				&agent_context->interface.interfaceid)))
		{
			zbx_interface_status	interface_status_local = {.interface = agent_context->interface};

			interface_status_local.interface.addr = NULL;
			interface_status = zbx_hashset_insert(&agent_context->poller_config->interfaces,
					&interface_status_local, sizeof(interface_status_local));
		}
		else
			zabbix_log(LOG_LEVEL_DEBUG, "updating existing interface");

		zbx_free(interface_status->error);
		interface_status->errcode = agent_context->ret;
		interface_status->itemid = agent_context->itemid;
		zbx_strlcpy(interface_status->host, agent_context->host.host, sizeof(interface_status->host));
		zbx_free(interface_status->key_orig);
		interface_status->key_orig = agent_context->key_orig;
		agent_context->key_orig = NULL;
	}

	if (SUCCEED == agent_context->ret)
	{
		zbx_preprocess_item_value(agent_context->itemid, agent_context->hostid,agent_context->value_type,
				agent_context->flags, &agent_context->result, &timespec, ITEM_STATE_NORMAL, NULL);
	}
	else
	{
		zbx_preprocess_item_value(agent_context->itemid, agent_context->hostid, agent_context->value_type,
					agent_context->flags, NULL, &timespec, ITEM_STATE_NOTSUPPORTED,
					agent_context->result.msg);

		interface_status->error = agent_context->result.msg;
		SET_MSG_RESULT(&agent_context->result, NULL);
	}

	zbx_vector_uint64_append(&agent_context->poller_config->itemids, agent_context->itemid);
	zbx_vector_int32_append(&agent_context->poller_config->errcodes, agent_context->ret);
	zbx_vector_int32_append(&agent_context->poller_config->lastclocks, timespec.sec);

	agent_context->poller_config->processing--;
	agent_context->poller_config->processed++;

	zabbix_log(LOG_LEVEL_DEBUG, "finished processing itemid:" ZBX_FS_UI64, agent_context->itemid);
	ret = agent_context->ret;

	agent_context_clean(agent_context);
	zbx_tcp_close(&agent_context->s);
	zbx_free(agent_context);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(ret));
}

static int	async_check_agent(zbx_dc_item_t *item, AGENT_RESULT *result, zbx_poller_config_t *poller_config)
{
	zbx_agent_context	*agent_context = zbx_malloc(NULL, sizeof(zbx_agent_context));
	int			ret = NOTSUPPORTED;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s() host:'%s' addr:'%s' key:'%s' conn:'%s'", __func__, item->host.host,
			item->interface.addr, item->key, zbx_tcp_connection_type_name(item->host.tls_connect));

	agent_context->poller_config = poller_config;
	agent_context->itemid = item->itemid;
	agent_context->hostid = item->host.hostid;
	agent_context->value_type = item->value_type;
	agent_context->flags = item->flags;
	agent_context->state = item->state;
	agent_context->host = item->host;
	agent_context->interface = item->interface;
	agent_context->interface.addr = (item->interface.addr == item->interface.dns_orig ?
			agent_context->interface.dns_orig : agent_context->interface.ip_orig);
	agent_context->key = item->key;
	agent_context->key_orig = zbx_strdup(NULL, item->key_orig);
	item->key = NULL;
	zbx_init_agent_result(&agent_context->result);

	switch (agent_context->host.tls_connect)
	{
		case ZBX_TCP_SEC_UNENCRYPTED:
			agent_context->tls_arg1 = NULL;
			agent_context->tls_arg2 = NULL;
			break;
#if defined(HAVE_GNUTLS) || defined(HAVE_OPENSSL)
		case ZBX_TCP_SEC_TLS_CERT:
			agent_context->tls_arg1 = agent_context->host.tls_issuer;
			agent_context->tls_arg2 = agent_context->host.tls_subject;
			break;
		case ZBX_TCP_SEC_TLS_PSK:
			agent_context->tls_arg1 = agent_context->host.tls_psk_identity;
			agent_context->tls_arg2 = agent_context->host.tls_psk;
			break;
#else
		case ZBX_TCP_SEC_TLS_CERT:
		case ZBX_TCP_SEC_TLS_PSK:
			SET_MSG_RESULT(result, zbx_dsprintf(NULL, "A TLS connection is configured to be used with agent"
					" but support for TLS was not compiled into %s.",
					get_program_type_string(program_type)));
			ret = CONFIG_ERROR;
			goto out;
#endif
		default:
			THIS_SHOULD_NEVER_HAPPEN;
			SET_MSG_RESULT(result, zbx_strdup(NULL, "Invalid TLS connection parameters."));
			ret = CONFIG_ERROR;
			goto out;
	}

	if (SUCCEED != zbx_socket_connect(&agent_context->s, SOCK_STREAM, agent_context->poller_config->config_source_ip,
			agent_context->interface.addr, agent_context->interface.port,
			agent_context->poller_config->config_timeout, agent_context->host.tls_connect, agent_context->tls_arg1))
	{
		goto out;
	}

#if defined(HAVE_GNUTLS) || defined(HAVE_OPENSSL)
	if (NULL != agent_context->interface.addr && SUCCEED != zbx_is_ip(agent_context->interface.addr))
		agent_context->server_name = agent_context->interface.addr;
#else
	ZBX_UNUSED(tls_arg1);
	ZBX_UNUSED(tls_arg2);
#endif

	poller_config->processing++;
	zbx_async_poller_add_task(poller_config->base, agent_context->s.socket, agent_context,
			agent_context->poller_config->config_timeout, agent_task_process, agent_task_free);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(SUCCEED));

	return SUCCEED;
out:
	agent_context_clean(agent_context);
	zbx_free(agent_context);
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%s", __func__, zbx_result_string(ret));

	return ret;
}

static void	httpagent_context_create(zbx_httpagent_context *httpagent_context)
{
	zbx_http_context_create(&httpagent_context->http_context);
}

static void	httpagent_context_clean(zbx_httpagent_context *httpagent_context)
{
	zbx_free(httpagent_context->item_context.status_codes);
	zbx_free(httpagent_context->item_context.posts);
	zbx_http_context_destroy(&httpagent_context->http_context);
}

static int	async_check_httpagent(zbx_dc_item_t *item, AGENT_RESULT *result, zbx_poller_config_t *poller_config)
{
	char			*error = NULL;
	int			ret;
	zbx_httpagent_context	*httpagent_context = zbx_malloc(NULL, sizeof(zbx_httpagent_context));
	CURLcode		err;
	CURLMcode		merr;

	httpagent_context_create(httpagent_context);

	httpagent_context->poller_config = poller_config;
	httpagent_context->item_context.itemid = item->itemid;
	httpagent_context->item_context.hostid = item->host.hostid;
	httpagent_context->item_context.value_type = item->value_type;
	httpagent_context->item_context.flags = item->flags;
	httpagent_context->item_context.state = item->state;
	httpagent_context->item_context.posts = item->posts;
	item->posts = NULL;
	httpagent_context->item_context.status_codes = item->status_codes;
	item->status_codes = NULL;

	if (SUCCEED != (ret = zbx_http_request_prepare(&httpagent_context->http_context, item->request_method,
			item->url, item->query_fields, item->headers, httpagent_context->item_context.posts,
			item->retrieve_mode, item->http_proxy, item->follow_redirects, item->timeout, 1,
			item->ssl_cert_file, item->ssl_key_file, item->ssl_key_password, item->verify_peer,
			item->verify_host, item->authtype, item->username, item->password, NULL, item->post_type,
			item->output_format, poller_config->config_source_ip, &error)))
	{
		SET_MSG_RESULT(result, error);

		goto fail;
	}

	if (CURLE_OK != (err = curl_easy_setopt(httpagent_context->http_context.easyhandle, CURLOPT_PRIVATE,
			httpagent_context)))
	{
		SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Cannot set pointer to private data: %s",
				curl_easy_strerror(err)));

		goto fail;
	}

	if (CURLM_OK != (merr = curl_multi_add_handle(poller_config->curl_handle,
			httpagent_context->http_context.easyhandle)))
	{
		SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Cannot add a standard curl handle to the multi stack: %s",
				curl_multi_strerror(merr)));

		goto fail;
	}

	poller_config->processing++;
	return SUCCEED;
fail:
	httpagent_context_clean(httpagent_context);
	zbx_free(httpagent_context);

	return NOTSUPPORTED;
}

static void	async_check_items(evutil_socket_t fd, short events, void *arg)
{
	zbx_dc_item_t		item, *items;
	AGENT_RESULT		results[ZBX_MAX_HTTPAGENT_ITEMS];
	int			errcodes[ZBX_MAX_HTTPAGENT_ITEMS];
	zbx_timespec_t		timespec;
	int			i, num;
	zbx_poller_config_t	*poller_config = (zbx_poller_config_t *)arg;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	items = &item;
	num = zbx_dc_config_get_poller_items(poller_config->poller_type, poller_config->config_timeout,
			poller_config->processing, &items);

	if (0 == num)
		goto exit;

	zbx_prepare_items(items, errcodes, num, results, MACRO_EXPAND_YES);

	for (i = 0; i < num; i++)
	{
		if (ITEM_TYPE_HTTPAGENT == items[i].type)
			errcodes[i] = async_check_httpagent(&items[i], &results[i], poller_config);
		else
			errcodes[i] = async_check_agent(&items[i], &results[i], poller_config);
	}

	zbx_timespec(&timespec);

	/* process item values */
	for (i = 0; i < num; i++)
	{
		if (NOTSUPPORTED == errcodes[i] || AGENT_ERROR == errcodes[i] || CONFIG_ERROR == errcodes[i])
		{
			zbx_preprocess_item_value(items[i].itemid, items[i].host.hostid, items[i].value_type,
					items[i].flags, NULL, &timespec, ITEM_STATE_NOTSUPPORTED, results[i].msg);

			zbx_vector_uint64_append(&poller_config->itemids, items[i].itemid);
			zbx_vector_int32_append(&poller_config->errcodes, errcodes[i]);
			zbx_vector_int32_append(&poller_config->lastclocks, timespec.sec);
		}
	}

	zbx_clean_items(items, num, results);
	zbx_dc_config_clean_items(items, NULL, num);

	if (items != &item)
		zbx_free(items);
exit:
	zbx_preprocessor_flush();

	if (0 != poller_config->interfaces.num_data)
	{
		zbx_hashset_iter_t	iter;
		zbx_interface_status	*interface_status;
		unsigned char		*data = NULL;
		size_t			data_alloc = 0, data_offset = 0;

		zabbix_log(LOG_LEVEL_DEBUG, "updating:%d interfaces", poller_config->interfaces.num_data);

		zbx_timespec(&timespec);

		zbx_hashset_iter_reset(&poller_config->interfaces, &iter);

		while (NULL != (interface_status = (zbx_interface_status *)zbx_hashset_iter_next(&iter)))
		{
			switch (interface_status->errcode)
			{
				case SUCCEED:
				case NOTSUPPORTED:
				case AGENT_ERROR:
					zbx_activate_item_interface(&timespec, &interface_status->interface,
							interface_status->itemid, ITEM_TYPE_ZABBIX,
							interface_status->host, &data, &data_alloc, &data_offset);
					break;
				case NETWORK_ERROR:
				case GATEWAY_ERROR:
				case TIMEOUT_ERROR:
					zbx_deactivate_item_interface(&timespec, &interface_status->interface,
							interface_status->itemid,
							ITEM_TYPE_ZABBIX, interface_status->host,
							interface_status->key_orig, &data, &data_alloc, &data_offset,
							poller_config->config_unavailable_delay,
							poller_config->config_unreachable_period,
							poller_config->config_unreachable_delay,
							interface_status->error);
					break;
				case CONFIG_ERROR:
					/* nothing to do */
					break;
				case SIG_ERROR:
					/* nothing to do, execution was forcibly interrupted by signal */
					break;
				default:
					zbx_error("unknown response code returned: %d", errcodes[i]);
					THIS_SHOULD_NEVER_HAPPEN;
			}

		}

		zbx_hashset_clear(&poller_config->interfaces);

		if (NULL != data)
		{
			zbx_availability_send(ZBX_IPC_AVAILABILITY_REQUEST, data, (zbx_uint32_t)data_offset, NULL);
			zbx_free(data);
		}
	}

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s():%d", __func__, num);

	poller_config->queued += num;
}

static void	process_item_result(CURL *easy_handle, CURLcode err)
{
	long			response_code;
	char			*error, *out = NULL;
	AGENT_RESULT		result;
	char			*status_codes;
	zbx_httpagent_context	*httpagent_context;
	zbx_dc_item_context_t	*item_context;
	zbx_timespec_t		timespec;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	curl_easy_getinfo(easy_handle, CURLINFO_PRIVATE, &httpagent_context);

	zbx_timespec(&timespec);

	zbx_init_agent_result(&result);
	status_codes = httpagent_context->item_context.status_codes;
	item_context = &httpagent_context->item_context;

	if (SUCCEED == zbx_http_handle_response(easy_handle, &httpagent_context->http_context, err, &response_code,
			&out, &error) && SUCCEED == zbx_handle_response_code(status_codes, response_code, out, &error))
	{
		SET_TEXT_RESULT(&result, out);
		out = NULL;
		zbx_preprocess_item_value(item_context->itemid, item_context->hostid,item_context->value_type,
				item_context->flags, &result, &timespec, ITEM_STATE_NORMAL, NULL);
	}
	else
	{
		SET_MSG_RESULT(&result, error);
		zbx_preprocess_item_value(item_context->itemid, item_context->hostid, item_context->value_type,
				item_context->flags, NULL, &timespec, ITEM_STATE_NOTSUPPORTED, result.msg);
	}

	zbx_free_agent_result(&result);
	zbx_free(out);

	zbx_vector_uint64_append(&httpagent_context->poller_config->itemids, httpagent_context->item_context.itemid);
	zbx_vector_int32_append(&httpagent_context->poller_config->errcodes, SUCCEED);
	zbx_vector_int32_append(&httpagent_context->poller_config->lastclocks, timespec.sec);

	httpagent_context->poller_config->processing--;
	httpagent_context->poller_config->processed++;

	zabbix_log(LOG_LEVEL_DEBUG, "finished processing itemid:" ZBX_FS_UI64, httpagent_context->item_context.itemid);

	curl_multi_remove_handle(httpagent_context->poller_config->curl_handle, easy_handle);
	httpagent_context_clean(httpagent_context);
	zbx_free(httpagent_context);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

static void	poller_requeue_items(zbx_poller_config_t *poller_config)
{
	int	nextcheck;

	if (0 == poller_config->itemids.values_num)
		return;

	zbx_dc_poller_requeue_items(poller_config->itemids.values, poller_config->lastclocks.values,
			poller_config->errcodes.values, poller_config->itemids.values_num,
			poller_config->poller_type, &nextcheck);

	zabbix_log(LOG_LEVEL_DEBUG, "%s() requeued:%d", __func__, poller_config->itemids.values_num);

	zbx_vector_uint64_clear(&poller_config->itemids);
	zbx_vector_int32_clear(&poller_config->lastclocks);
	zbx_vector_int32_clear(&poller_config->errcodes);

	if (FAIL != nextcheck && nextcheck <= time(NULL))
		event_active(poller_config->async_check_items_timer, 0, 0);
}

static void	zbx_interface_status_clean(zbx_interface_status *interface_status)
{
	zbx_free(interface_status->key_orig);
	zbx_free(interface_status->error);
}

static void	http_agent_poller_init(zbx_poller_config_t *poller_config, zbx_thread_poller_args *poller_args_in,
		event_callback_fn async_check_items_callback)
{
	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	zbx_hashset_create_ext(&poller_config->interfaces, 100, ZBX_DEFAULT_UINT64_HASH_FUNC,
			ZBX_DEFAULT_UINT64_COMPARE_FUNC, (zbx_clean_func_t)zbx_interface_status_clean,
			ZBX_DEFAULT_MEM_MALLOC_FUNC, ZBX_DEFAULT_MEM_REALLOC_FUNC, ZBX_DEFAULT_MEM_FREE_FUNC);
	zbx_vector_uint64_create(&poller_config->itemids);
	zbx_vector_int32_create(&poller_config->lastclocks);
	zbx_vector_int32_create(&poller_config->errcodes);

	if (NULL == (poller_config->base = event_base_new()))
	{
		zabbix_log(LOG_LEVEL_ERR, "cannot initialize event base");
		exit(EXIT_FAILURE);
	}

	poller_config->config_source_ip = poller_args_in->config_comms->config_source_ip;
	poller_config->config_timeout = poller_args_in->config_comms->config_timeout;
	poller_config->poller_type = poller_args_in->poller_type;
	poller_config->config_unavailable_delay = poller_args_in->config_unavailable_delay;
	poller_config->config_unreachable_delay = poller_args_in->config_unreachable_delay;
	poller_config->config_unreachable_period = poller_args_in->config_unreachable_period;

	if (NULL == (poller_config->async_check_items_timer = evtimer_new(poller_config->base,
			async_check_items_callback, poller_config)))
	{
		zabbix_log(LOG_LEVEL_ERR, "cannot create async items timer event");
		exit(EXIT_FAILURE);
	}

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}

static void	http_agent_poller_destroy(zbx_poller_config_t *poller_config)
{
	event_base_free(poller_config->base);
	zbx_vector_uint64_clear(&poller_config->itemids);
	zbx_vector_int32_clear(&poller_config->lastclocks);
	zbx_vector_int32_clear(&poller_config->errcodes);
	zbx_vector_uint64_destroy(&poller_config->itemids);
	zbx_vector_int32_destroy(&poller_config->lastclocks);
	zbx_vector_int32_destroy(&poller_config->errcodes);
}

ZBX_THREAD_ENTRY(httpagent_poller_thread, args)
{
	zbx_thread_poller_args	*poller_args_in = (zbx_thread_poller_args *)(((zbx_thread_args_t *)args)->args);

	double			sec, total_sec = 0.0;
	time_t			last_stat_time;
	zbx_ipc_async_socket_t	rtc;
	const zbx_thread_info_t	*info = &((zbx_thread_args_t *)args)->info;
	int			server_num = ((zbx_thread_args_t *)args)->info.server_num;
	int			process_num = ((zbx_thread_args_t *)args)->info.process_num;
	unsigned char		process_type = ((zbx_thread_args_t *)args)->info.process_type;
	struct timeval		tv = {1, 0};
	zbx_poller_config_t	poller_config = {.queued = 0, .processed = 0};

#define	STAT_INTERVAL	5	/* if a process is busy and does not sleep then update status not faster than */
				/* once in STAT_INTERVAL seconds */

	zabbix_log(LOG_LEVEL_INFORMATION, "%s #%d started [%s #%d]", get_program_type_string(info->program_type),
			server_num, get_process_type_string(process_type), process_num);

	zbx_update_selfmon_counter(info, ZBX_PROCESS_STATE_BUSY);

	zbx_setproctitle("%s #%d started", get_process_type_string(process_type), process_num);
	last_stat_time = time(NULL);

	zbx_rtc_subscribe(process_type, process_num, NULL, 0, poller_args_in->config_comms->config_timeout, &rtc);

	http_agent_poller_init(&poller_config, poller_args_in, async_check_items);
	poller_config.curl_handle = zbx_async_httpagent_init(poller_config.base, process_item_result);

	while (ZBX_IS_RUNNING())
	{
		zbx_uint32_t	rtc_cmd;
		unsigned char	*rtc_data;
		struct timeval	tv_pending;

		sec = zbx_time();
		zbx_update_env(get_process_type_string(process_type), sec);

		if (0 == evtimer_pending(poller_config.async_check_items_timer, &tv_pending))
			evtimer_add(poller_config.async_check_items_timer, &tv);

		event_base_loop(poller_config.base, EVLOOP_ONCE);

		poller_requeue_items(&poller_config);

		total_sec += zbx_time() - sec;

		if (STAT_INTERVAL <= time(NULL) - last_stat_time)
		{
			zbx_setproctitle("%s #%d [got %d values, queued %d in " ZBX_FS_DBL " sec]",
				get_process_type_string(process_type), process_num, poller_config.processed,
				poller_config.queued, total_sec);

			poller_config.processed = 0;
			poller_config.queued = 0;
			total_sec = 0.0;
			last_stat_time = time(NULL);
		}

		if (SUCCEED == zbx_rtc_wait(&rtc, info, &rtc_cmd, &rtc_data, 0) && 0 != rtc_cmd)
		{
			if (ZBX_RTC_SHUTDOWN == rtc_cmd)
				break;
		}
	}

	http_agent_poller_destroy(&poller_config);

	zbx_setproctitle("%s #%d [terminated]", get_process_type_string(process_type), process_num);

	while (1)
		zbx_sleep(SEC_PER_MIN);
#undef STAT_INTERVAL
}
