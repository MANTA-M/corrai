import type { Ref } from "vue";
import router from "@/router";

export type ApiMessageLevel = "success" | "info" | "warning" | "error";

export interface ApiMessage {
    level: string;
    text: string;
    style?: string;
    title?: string;
}

export interface ApiResponse {
    messages?: ApiMessage[];
}

export interface RestResponse {
    status: number;
    json?: ApiResponse;
    textData?: string;
}

export type Methods = "GET" | "POST" | "PUT" | "DELETE";

type URLParams = Record<string, string | number | boolean> | undefined | null;

/**
 * Web-service client, in charge of creating URL, error and status handling and request construction and response parsing.
 */
export class WSClient {
    static readonly rootApi = import.meta.env.VITE_WS_URL || "";
    loading?: Ref<boolean>;
    messages?: Ref<ApiMessage[]>;
    status_code?: number = -1;
    resetMessages?: boolean = false;
    no_redirect?: boolean;
    no_403_redirect?: boolean;
    abort_controler?: AbortController;
    auth_token?: string;

    /**
     * Create web-service client
     * 
     * @param {Ref<boolean> | undefined} loading - to inform if the query is running.
     * @param {Ref<ApiMessage[]> | undefined} messages - to return possible feedback messages.
     * @param {boolean | undefined} no_redirect - Don't redirect to login or paywall if 401 or 402 is returned.
     * @param {boolean} [resetMessages=true] - Blank feed-back message list before request.
     * @param {boolean} [no_403_redirect=true] - Don't redirect to not allowed page if 403 is returned.
     */
    constructor(
        loading?: Ref<boolean>,
        messages?: Ref<ApiMessage[]>,
        no_redirect?: boolean,
        resetMessages = true,
        no_403_redirect = true
    ) {
        this.loading = loading;
        this.messages = messages;
        this.no_redirect = no_redirect;
        this.no_403_redirect = no_403_redirect ?? true;
        this.resetMessages = resetMessages;
    }

    /**
     * Low level query, handle loading and redirection if not auth.
     *
     * @param method
     * @param url
     * @param data
     * @param headers
     * @returns
     */
    async query(
        method: Methods,
        url: string,
        data: BodyInit | FormData | null | undefined,
        headers: HeadersInit | undefined
    ): Promise<Response> {
        this.onLoading(true);

        this.abort_controler = new AbortController();
        const signal = this.abort_controler.signal;

        try {
            const httpResponse = await fetch(url, {
                mode: "cors",
                credentials: "include",
                method: method,
                headers: headers,
                body: data,
                signal,
            });
            this.onLoading(false);
            this.status_code = httpResponse.status;
            this.abort_controler = undefined;
            if (router) {
                const route = router.currentRoute.value;
                if (route.name != "login") {
                    if (httpResponse.status == 401 && !this.no_redirect) {
                        router.push({
                            name: "not-authenticated"
                        });
                    }
                }
            }

            return httpResponse;
        } catch (error) {
            this.onLoading(false);
            this.abort_controler = undefined;
            throw error;
        }
    }

    /**
     * Query adapted to generic JSON WS.
     * Handle messages and non JSON responses.
     *
     * @param method
     * @param url
     * @param data
     * @returns
     */
    async queryAllTypes(method: Methods, url: string, data: unknown, queryType: string): Promise<any> {
        let headers: Headers;
        let body_param: BodyInit | null;
        if (queryType === "form") {
            // Do not set Content-Type so the browser can set multipart/form-data with boundary
            headers = new Headers({ Accept: "application/json" });
            body_param = data ? (data as BodyInit) : null;
        } else if (queryType === "text") {
            headers = new Headers({ "Content-Type": "text/plain" });
            body_param = data ? (data as string) : null;
        } else {
            headers = new Headers({ "Content-Type": "application/json" });
            body_param = data ? JSON.stringify(data) : null;
        }
        if(this.auth_token) {
            headers.append("Authorization", "Bearer " + this.auth_token);
        }
        const httpResponse = await this.query(method, url, body_param, headers);
        const contentType = httpResponse.headers.get("Content-type");
        if (contentType && contentType.startsWith("application/json")) {
            try {
                const json = await httpResponse.json();

                if (!json) {
                    return this.handleText(httpResponse.ok, await httpResponse.text());
                }

                this.setMessages(json?.messages);
                if (json?.error_code) {
                    this.status_code = json.error_code;
                }
                if (httpResponse.status > 199 && httpResponse.status < 300) {
                    return Promise.resolve(json);
                }
                return Promise.reject(json);
            } catch (err) {
                return this.handleError(err);
            }
        }

        return this.handleText(httpResponse.ok, await httpResponse.text());
    }

    handleText(isOk: boolean, text: string): Promise<any> {
        if (isOk) {
            return Promise.resolve({ text: text });
        }
        this.setMessages([{ level: "error", text: "Error " + text }]);
        return Promise.reject();
    }

    handleError(error: unknown): Promise<ApiResponse> {
        this.onLoading(false);

        if (Array.isArray(error)) {
            this.setMessages(error);
        } else {
            const message = error instanceof Error ? error.message : String(error);
            this.setMessages([{ level: "error", text: "Error " + message }]);
        }

        return Promise.reject(error);
    }

    setMessages(input: ApiMessage[] | undefined): void {
        if (this.messages) {
            if (this.resetMessages) {
                this.messages.value = [];
            }
            const m_arr = input;
            if (m_arr) {
                this.messages.value.push(...m_arr);
            }
        }
    }

    onLoading(isLoading: boolean): void {
        if (this.loading) {
            this.loading.value = isLoading;
        }
    }

    getWsUrl(
        url: string,
        urlParams?: URLParams
    ): string {
        const urlParamsSearchParams = urlParams ? new URLSearchParams(Object.fromEntries(
            Object.entries(urlParams).map(([key, value]) => [key, String(value)])
          )) : undefined;
        const queryString = urlParamsSearchParams?.toString() ?? "";
        return WSClient.rootApi + url + (queryString !== "" ? ("?" + queryString) : '');
    }

    queryWs<T>(
        method: Methods,
        url: string,
        urlParams?: URLParams,
        body?: unknown,
        queryType = "json"
    ): Promise<T> {
        return this.queryAllTypes(method, this.getWsUrl(url, urlParams), body, queryType);
    }

    abort(reason: string): void {
        this.abort_controler?.abort(reason);
    }
}
