/// <reference types="vite/client" />

declare function route(
    name: string,
    parameters?: Record<string, unknown>,
    absolute?: boolean,
): string;
