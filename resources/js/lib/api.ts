export class ApiError extends Error {
    constructor(public status: number, public code: string, message: string) { super(message); }
}
export async function api<T>(path: string, options: {method?: string; body?: unknown; signal?: AbortSignal} = {}): Promise<T> {
    const form = options.body instanceof FormData;
    const token = decodeURIComponent(document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11) || '');
    const response = await fetch(path, {
        method: options.method || 'GET', credentials: 'same-origin', signal: options.signal,
        headers: {'Accept': 'application/json', 'X-XSRF-TOKEN': token, ...(!form && options.body !== undefined ? {'Content-Type': 'application/json'} : {})},
        body: options.body === undefined ? undefined : form ? options.body as FormData : JSON.stringify(options.body),
    }).catch(error => {if (error.name === 'AbortError') throw error; throw new ApiError(0, 'network_error', 'Нет соединения. Проверьте сеть и повторите отправку.');});
    const result = await response.json().catch(() => null);
    if (!response.ok) {
        const fields = result?.errors ? Object.values(result.errors).flat().join(' ') : null;
        throw new ApiError(response.status, result?.error?.code || 'request_failed', fields || result?.error?.message || (response.status === 419 ? 'Сессия устарела. Обновите страницу.' : 'Не удалось выполнить запрос.'));
    }
    return result as T;
}
