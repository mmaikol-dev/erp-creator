import { Head, usePage } from '@inertiajs/react';
import {
    Bot,
    ChevronDown,
    Code2,
    FileText,
    ImageIcon,
    Loader2,
    Mic,
    Paperclip,
    Plus,
    Send,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AI Assistant',
        href: '/ai-assistant',
    },
];

type Message = {
    id: string | number;
    role: 'user' | 'assistant';
    content: string;
    model?: string;
    fallbackUsed?: boolean;
    stage?: 'plan' | 'execution';
    plan?: string;
    planModel?: string;
    thinking?: string;
    planThinking?: string;
};

type AssistantMode = 'auto' | 'deep';

type ConversationSummary = {
    id: number;
    title?: string | null;
    preview: string;
    updated_at: string;
    message_count: number;
};

type ChatResponse = {
    ok: boolean;
    reply?: string;
    model?: string;
    plan?: string;
    plan_model?: string;
    conversation_id?: number;
    fallback_used?: boolean;
    warnings?: string[];
    error?: string;
    message?: string;
    errors?: Record<string, string[]>;
};

type NewConversationResponse = {
    ok: boolean;
    conversation_id?: number;
    messages?: Message[];
    conversation?: ConversationSummary;
    error?: string;
    message?: string;
    errors?: Record<string, string[]>;
};

type ConversationResponse = {
    ok: boolean;
    conversation_id?: number;
    messages?: Message[];
    conversation?: ConversationSummary;
    error?: string;
    message?: string;
    errors?: Record<string, string[]>;
};

type StreamEvent =
    | {
          type: 'chunk';
          content: string;
      }
    | {
          type: 'plan_chunk';
          content: string;
      }
    | {
          type: 'heartbeat';
          status?: string;
      }
    | {
          type: 'done';
          conversation_id?: number;
          model?: string;
          fallback_used?: boolean;
          plan?: string | null;
          plan_model?: string | null;
          thinking?: string | null;
          plan_thinking?: string | null;
          warnings?: string[];
      }
    | {
          type: 'tool_activity';
          phase?: string;
          status?: string;
          round?: number;
          tools?: string[];
          calls?: Array<{
              tool?: string;
              path?: string | null;
              query?: string | null;
          }>;
          results?: Array<{
              tool?: string;
              ok?: boolean;
              path?: string | null;
              error?: string | null;
          }>;
      }
    | {
          type: 'error';
          error: string;
      };

type AssistantPageProps = {
    conversationId?: number | null;
    messages?: Message[];
    conversations?: ConversationSummary[];
};

const FAST_MODE_TIMEOUT_MS = 600000;
const DEEP_MODE_TIMEOUT_MS = 900000;

function streamStatusLabel(status: string | null, mode: AssistantMode): string {
    if (mode === 'deep') {
        return (
            {
                starting: 'Deep mode: starting...',
                planning: 'Deep mode: planning...',
                executing: 'Deep mode: executing...',
                finalizing: 'Deep mode: finalizing...',
            }[status ?? ''] ?? 'Deep mode: planning and executing...'
        );
    }

    return (
        {
            starting: 'Starting...',
            bootstrap_tools: 'Bootstrapping project snapshot...',
            building_context: 'Loading app context...',
            retrieving_context: 'Retrieving project context...',
            model_round_1: 'Thinking...',
            model_round_2: 'Second pass...',
            model_round_3: 'Refining response...',
            running_tools: 'Running tools...',
            verifying_typescript: 'Running TypeScript checks...',
            finalizing: 'Finalizing...',
        }[status ?? ''] ?? 'Thinking...'
    );
}

function streamStatusDetail(status: string | null, mode: AssistantMode): string {
    if (mode === 'deep') {
        return (
            {
                starting: 'Preparing deep-mode context.',
                planning:
                    'Running planning stage before code generation. This can take longer.',
                executing:
                    'Running execution stage based on the plan.',
                finalizing: 'Saving and returning final deep-mode response.',
            }[status ?? ''] ??
            'Deep mode runs a planning stage before execution, so this can take longer.'
        );
    }

    return (
        {
            starting: 'Preparing context and contacting model...',
            bootstrap_tools:
                'Collecting initial project file map so tool calls can start faster.',
            building_context: 'Collecting framework context (routes/app info)...',
            retrieving_context:
                'Retrieving project snippets (index/embeddings). First request can be slower.',
            model_round_1: 'Model is generating response...',
            model_round_2: 'Fallback/second model pass in progress...',
            model_round_3: 'Additional model pass in progress...',
            running_tools:
                'Model requested file/code tools. Server is executing them now.',
            verifying_typescript:
                'Validating generated TypeScript before final response.',
            finalizing: 'Assembling final response for display...',
        }[status ?? ''] ?? 'Model is working...'
    );
}

function AssistantDetails({
    plan,
    planModel,
    thinking,
    planThinking,
}: {
    plan?: string;
    planModel?: string;
    thinking?: string;
    planThinking?: string;
}) {
    const hasPlan = typeof plan === 'string' && plan.trim() !== '';
    const hasThinking = typeof thinking === 'string' && thinking.trim() !== '';
    const hasPlanThinking =
        typeof planThinking === 'string' && planThinking.trim() !== '';

    if (!hasPlan && !hasThinking && !hasPlanThinking) {
        return null;
    }

    return (
        <Collapsible className="mt-2">
            <CollapsibleTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground"
                >
                    <ChevronDown className="size-3 transition-transform data-[state=open]:rotate-180" />
                    Details
                </button>
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-2 space-y-2 rounded-md border bg-muted/30 p-2">
                {hasPlan && (
                    <div>
                        <p className="text-[11px] font-medium text-muted-foreground">
                            Planning
                            {planModel ? ` · ${planModel}` : ''}
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-[12px]">
                            {plan}
                        </p>
                    </div>
                )}
                {hasPlanThinking && (
                    <div>
                        <p className="text-[11px] font-medium text-muted-foreground">
                            Plan Thinking
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-[12px]">
                            {planThinking}
                        </p>
                    </div>
                )}
                {hasThinking && (
                    <div>
                        <p className="text-[11px] font-medium text-muted-foreground">
                            Thinking
                        </p>
                        <p className="mt-1 whitespace-pre-wrap text-[12px]">
                            {thinking}
                        </p>
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]');

    if (!meta) {
        return '';
    }

    return meta.getAttribute('content') ?? '';
}

function parseApiError(response: Response, payload: unknown): string {
    if (typeof payload === 'object' && payload !== null) {
        const data = payload as Partial<ChatResponse>;

        if (typeof data.error === 'string' && data.error.trim() !== '') {
            return data.error;
        }

        if (typeof data.message === 'string' && data.message.trim() !== '') {
            return data.message;
        }

        if (data.errors && typeof data.errors === 'object') {
            const firstKey = Object.keys(data.errors)[0];

            if (firstKey) {
                const firstError = data.errors[firstKey]?.[0];

                if (typeof firstError === 'string' && firstError.trim() !== '') {
                    return firstError;
                }
            }
        }
    }

    if (response.status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }

    return 'The assistant did not return a response.';
}

export default function AIAssistant() {
    const page = usePage<AssistantPageProps>();
    const initialConversationId = page.props.conversationId ?? null;
    const initialMessages = Array.isArray(page.props.messages)
        ? page.props.messages
        : [];
    const initialConversations = Array.isArray(page.props.conversations)
        ? page.props.conversations
        : [];

    const [prompt, setPrompt] = useState('');
    const [messages, setMessages] = useState<Message[]>(initialMessages);
    const [isSending, setIsSending] = useState(false);
    const [isCreatingConversation, setIsCreatingConversation] = useState(false);
    const [switchingConversationId, setSwitchingConversationId] = useState<
        number | null
    >(null);
    const [warnings, setWarnings] = useState<string[]>([]);
    const [mode, setMode] = useState<AssistantMode>('auto');
    const [streamStatus, setStreamStatus] = useState<string | null>(null);
    const [statusTrail, setStatusTrail] = useState<string[]>([]);
    const [requestStartedAt, setRequestStartedAt] = useState<number | null>(
        null,
    );
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [streamedChars, setStreamedChars] = useState(0);
    const [toolActivity, setToolActivity] = useState<string[]>([]);
    const [planningPreview, setPlanningPreview] = useState('');
    const [conversationId, setConversationId] = useState<number | null>(
        initialConversationId,
    );
    const [conversations, setConversations] =
        useState<ConversationSummary[]>(initialConversations);

    const suggestions = [
        { title: 'Plan Laravel auth flow', icon: FileText },
        { title: 'Generate UI image prompt', icon: ImageIcon },
        { title: 'Explain model routing policy', icon: Bot },
        { title: 'Write controller + tests', icon: Code2 },
    ];

    const history = useMemo(
        () => messages.map(({ role, content }) => ({ role, content })),
        [messages],
    );

    useEffect(() => {
        if (!isSending || requestStartedAt === null) {
            setElapsedSeconds(0);
            return;
        }

        const tick = () => {
            const elapsed = Math.max(
                0,
                Math.floor((Date.now() - requestStartedAt) / 1000),
            );
            setElapsedSeconds(elapsed);
        };

        tick();
        const interval = window.setInterval(tick, 1000);

        return () => {
            window.clearInterval(interval);
        };
    }, [isSending, requestStartedAt]);

    const submitPrompt = async (nextPrompt?: string) => {
        const content = (nextPrompt ?? prompt).trim();

        if (!content || isSending || isCreatingConversation) {
            return;
        }

        const userMessage: Message = {
            id: `user-${Date.now()}`,
            role: 'user',
            content,
        };

        const currentHistory = [
            ...history.slice(-19),
            { role: 'user' as const, content },
        ];

        setMessages((previous) => [...previous, userMessage]);
        setPrompt('');
        setWarnings([]);
        setStreamStatus(null);
        setStatusTrail(['starting']);
        setRequestStartedAt(Date.now());
        setElapsedSeconds(0);
        setStreamedChars(0);
        setToolActivity([]);
        setPlanningPreview('');
        setIsSending(true);

        try {
            const controller = new AbortController();
            const requestTimeoutMs =
                mode === 'deep'
                    ? DEEP_MODE_TIMEOUT_MS
                    : FAST_MODE_TIMEOUT_MS;
            const timeout = window.setTimeout(
                () => controller.abort(),
                requestTimeoutMs,
            );

            const streamId = `assistant-stream-${Date.now()}`;

                setMessages((previous) => [
                    ...previous,
                    {
                        id: streamId,
                        role: 'assistant',
                        content: '',
                        stage: 'execution',
                    },
                ]);

                const response = await fetch('/ai-assistant/chat/stream', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        Accept: 'application/x-ndjson, application/json',
                    },
                    body: JSON.stringify({
                        message: content,
                        history: currentHistory,
                        mode,
                        conversation_id: conversationId,
                    }),
                }).finally(() => window.clearTimeout(timeout));

                if (!response.ok || !response.body) {
                    const raw = await response.text();
                    let payload: unknown = null;

                    try {
                        payload = JSON.parse(raw);
                    } catch {
                        payload = raw;
                    }

                    throw new Error(parseApiError(response, payload));
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let finalModel: string | undefined;
                let finalFallbackUsed = false;
                let finalWarnings: string[] = [];
                let finalPlan: string | undefined;
                let finalPlanModel: string | undefined;
                let finalThinking: string | undefined;
                let finalPlanThinking: string | undefined;

                while (true) {
                    const { done, value } = await reader.read();

                    if (done) {
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() ?? '';

                    for (const line of lines) {
                        const trimmed = line.trim();

                        if (trimmed === '') {
                            continue;
                        }

                        let event: StreamEvent | null = null;

                        try {
                            event = JSON.parse(trimmed) as StreamEvent;
                        } catch {
                            event = null;
                        }

                        if (!event) {
                            continue;
                        }

                        if (event.type === 'chunk') {
                            setStreamedChars((previous) => previous + event.content.length);
                            setMessages((previous) =>
                                previous.map((message) =>
                                    message.id === streamId
                                        ? {
                                              ...message,
                                              content:
                                                  message.content +
                                                  event.content,
                                          }
                                        : message,
                                ),
                            );
                        }

                        if (event.type === 'plan_chunk') {
                            setPlanningPreview((previous) => previous + event.content);
                            setStreamedChars((previous) => previous + event.content.length);
                        }

                        if (event.type === 'done') {
                            if (typeof event.conversation_id === 'number') {
                                setConversationId(event.conversation_id);
                            }

                            finalModel =
                                typeof event.model === 'string'
                                    ? event.model
                                    : undefined;
                            finalFallbackUsed =
                                typeof event.fallback_used === 'boolean'
                                    ? event.fallback_used
                                    : false;
                            finalWarnings = Array.isArray(event.warnings)
                                ? event.warnings.filter(
                                      (warning): warning is string =>
                                          typeof warning === 'string',
                                  )
                                : [];
                            finalPlan =
                                typeof event.plan === 'string' &&
                                event.plan.trim() !== ''
                                    ? event.plan
                                    : undefined;
                            finalPlanModel =
                                typeof event.plan_model === 'string' &&
                                event.plan_model.trim() !== ''
                                    ? event.plan_model
                                    : undefined;
                            finalThinking =
                                typeof event.thinking === 'string' &&
                                event.thinking.trim() !== ''
                                    ? event.thinking
                                    : undefined;
                            finalPlanThinking =
                                typeof event.plan_thinking === 'string' &&
                                event.plan_thinking.trim() !== ''
                                    ? event.plan_thinking
                                    : undefined;
                        }

                        if (event.type === 'tool_activity') {
                            const roundText =
                                typeof event.round === 'number'
                                    ? `round ${event.round}`
                                    : 'round ?';

                            if (
                                event.phase === 'tool_calls' &&
                                Array.isArray(event.calls)
                            ) {
                                const summary = event.calls
                                    .map((call) => {
                                        const tool =
                                            typeof call.tool === 'string'
                                                ? call.tool
                                                : 'unknown';
                                        const path =
                                            typeof call.path === 'string' &&
                                            call.path.trim() !== ''
                                                ? ` path=${call.path}`
                                                : '';
                                        const query =
                                            typeof call.query === 'string' &&
                                            call.query.trim() !== ''
                                                ? ` query=${call.query}`
                                                : '';

                                        return `${tool}${path}${query}`;
                                    })
                                    .join(' | ');

                                setToolActivity((previous) => [
                                    ...previous.slice(-14),
                                    `[${roundText}] requested: ${summary}`,
                                ]);
                            } else if (
                                event.phase === 'tool_results' &&
                                Array.isArray(event.results)
                            ) {
                                const summary = event.results
                                    .map((result) => {
                                        const tool =
                                            typeof result.tool === 'string'
                                                ? result.tool
                                                : 'unknown';
                                        const ok =
                                            result.ok === true
                                                ? 'ok'
                                                : 'failed';

                                        return `${tool}:${ok}`;
                                    })
                                    .join(' | ');

                                setToolActivity((previous) => [
                                    ...previous.slice(-14),
                                    `[${roundText}] results: ${summary}`,
                                ]);
                            } else if (event.phase === 'bootstrap') {
                                setToolActivity((previous) => [
                                    ...previous.slice(-14),
                                    'Bootstrap snapshot prepared.',
                                ]);
                            }
                        }

                        if (event.type === 'heartbeat') {
                            const nextStatus =
                                typeof event.status === 'string'
                                    ? event.status
                                    : null;

                            setStreamStatus(nextStatus);

                            if (nextStatus) {
                                setStatusTrail((previous) => {
                                    if (
                                        previous[previous.length - 1] ===
                                        nextStatus
                                    ) {
                                        return previous;
                                    }

                                    return [...previous.slice(-5), nextStatus];
                                });
                            }
                        }

                        if (event.type === 'error') {
                            throw new Error(event.error);
                        }
                    }
                }

                setMessages((previous) =>
                    previous.map((message) =>
                        message.id === streamId
                            ? {
                                  ...message,
                                  model: finalModel,
                                  fallbackUsed: finalFallbackUsed,
                                  plan: finalPlan,
                                  planModel: finalPlanModel,
                                  thinking: finalThinking,
                                  planThinking: finalPlanThinking,
                              }
                            : message,
                    ),
                );

            setWarnings(finalWarnings);
        } catch (error) {
            const message =
                error instanceof DOMException && error.name === 'AbortError'
                    ? `Request timed out after ${Math.floor((mode === 'deep' ? DEEP_MODE_TIMEOUT_MS : FAST_MODE_TIMEOUT_MS) / 1000)} seconds. ${mode === 'deep' ? 'Deep mode runs two model passes and can take longer; try Fast mode for quicker replies.' : 'The server/model is still taking too long; check Ollama/server health and try again.'}`
                    : error instanceof Error
                      ? error.message
                      : 'Unexpected assistant error.';

            setMessages((previous) => [
                ...previous,
                {
                    id: `assistant-error-${Date.now()}`,
                    role: 'assistant',
                    content: message,
                },
            ]);
        } finally {
            setStreamStatus(null);
            setIsSending(false);
        }
    };

    const startNewChat = async () => {
        if (isSending || isCreatingConversation) {
            return;
        }

        setWarnings([]);
        setPrompt('');
        setIsCreatingConversation(true);

        try {
            const response = await fetch('/ai-assistant/conversations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    Accept: 'application/json',
                },
            });

            const raw = await response.text();
            let payload: NewConversationResponse | null = null;

            if (raw.trim() !== '') {
                let parsed: unknown = null;

                try {
                    parsed = JSON.parse(raw) as unknown;
                } catch {
                    parsed = null;
                }

                if (parsed && typeof parsed === 'object') {
                    payload = parsed as NewConversationResponse;
                }
            }

            if (
                !response.ok ||
                !payload ||
                typeof payload.ok !== 'boolean' ||
                !payload.ok ||
                typeof payload.conversation_id !== 'number'
            ) {
                throw new Error(parseApiError(response, payload));
            }

            setConversationId(payload.conversation_id);
            setMessages([]);
            if (payload.conversation) {
                setConversations((previous) => [
                    payload.conversation as ConversationSummary,
                    ...previous.filter(
                        (item) => item.id !== payload.conversation?.id,
                    ),
                ]);
            }
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Could not start a new chat.';

            setMessages((previous) => [
                ...previous,
                {
                    id: `assistant-error-${Date.now()}`,
                    role: 'assistant',
                    content: message,
                },
            ]);
        } finally {
            setIsCreatingConversation(false);
        }
    };

    const loadConversation = async (targetConversationId: number) => {
        if (
            isSending ||
            isCreatingConversation ||
            switchingConversationId !== null ||
            targetConversationId === conversationId
        ) {
            return;
        }

        setWarnings([]);
        setPrompt('');
        setSwitchingConversationId(targetConversationId);

        try {
            const response = await fetch(
                `/ai-assistant/conversations/${targetConversationId}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            const raw = await response.text();
            let payload: ConversationResponse | null = null;

            if (raw.trim() !== '') {
                let parsed: unknown = null;

                try {
                    parsed = JSON.parse(raw) as unknown;
                } catch {
                    parsed = null;
                }

                if (parsed && typeof parsed === 'object') {
                    payload = parsed as ConversationResponse;
                }
            }

            if (
                !response.ok ||
                !payload ||
                typeof payload.ok !== 'boolean' ||
                !payload.ok ||
                typeof payload.conversation_id !== 'number' ||
                !Array.isArray(payload.messages)
            ) {
                throw new Error(parseApiError(response, payload));
            }

            setConversationId(payload.conversation_id);
            setMessages(payload.messages);

            if (payload.conversation) {
                setConversations((previous) => [
                    payload.conversation as ConversationSummary,
                    ...previous.filter(
                        (item) => item.id !== payload.conversation?.id,
                    ),
                ]);
            }
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : 'Could not load conversation.';

            setMessages((previous) => [
                ...previous,
                {
                    id: `assistant-error-${Date.now()}`,
                    role: 'assistant',
                    content: message,
                },
            ]);
        } finally {
            setSwitchingConversationId(null);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI Assistant" />
            <div className="h-full flex-1 overflow-hidden">
                <div className="flex h-full flex-col lg:flex-row">
                    <section className="flex min-h-[70vh] flex-1 flex-col bg-background">
                        <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col px-4 pb-6 pt-10 sm:px-6 lg:pt-12">
                            {messages.length === 0 ? (
                                <>
                                    <div className="space-y-4 text-center">
                                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                                            Welcome to Script
                                        </h1>
                                        <p className="text-sm text-muted-foreground sm:text-base">
                                            Ask for planning with `glm-5:cloud`,
                                            coding with `qwen3-coder-next:cloud`,
                                            and retrieval with
                                            `qwen3-embedding:0.6b`.
                                        </p>
                                    </div>

                                    <div className="mx-auto mt-10 grid w-full max-w-xl grid-cols-1 gap-3 sm:grid-cols-2">
                                        {suggestions.map((item) => (
                                            <Card
                                                key={item.title}
                                                className="flex-row items-center justify-between px-4 py-3"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <div className="rounded-md bg-secondary p-2 text-secondary-foreground">
                                                        <item.icon className="size-4" />
                                                    </div>
                                                    <span className="text-sm font-medium">
                                                        {item.title}
                                                    </span>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8"
                                                    onClick={() =>
                                                        submitPrompt(item.title)
                                                    }
                                                >
                                                    <Plus className="size-4" />
                                                </Button>
                                            </Card>
                                        ))}
                                    </div>
                                </>
                            ) : (
                                <div className="flex-1 space-y-3 overflow-y-auto rounded-xl border bg-muted/20 p-3">
                                    {messages.map((message) => (
                                        <div
                                            key={message.id}
                                            className={
                                                message.role === 'user'
                                                    ? 'ml-auto max-w-[85%] rounded-xl bg-primary px-3 py-2 text-sm text-primary-foreground'
                                                    : 'mr-auto max-w-[90%] rounded-xl border bg-background px-3 py-2 text-sm'
                                            }
                                        >
                                            <p className="whitespace-pre-wrap">
                                                {message.content}
                                            </p>
                                            {message.role === 'assistant' &&
                                                message.model && (
                                                    <p className="mt-2 text-[11px] text-muted-foreground">
                                                        {message.stage === 'plan'
                                                            ? 'Plan'
                                                            : message.stage ===
                                                                'execution'
                                                              ? 'Execution'
                                                              : 'Assistant'}{' '}
                                                        · {message.model}
                                                        {message.fallbackUsed
                                                            ? ' (fallback)'
                                                            : ''}
                                                    </p>
                                                )}
                                            {message.role === 'assistant' && (
                                                <AssistantDetails
                                                    plan={message.plan}
                                                    planModel={message.planModel}
                                                    thinking={message.thinking}
                                                    planThinking={message.planThinking}
                                                />
                                            )}
                                        </div>
                                    ))}
                                    {isSending && (
                                        <div className="mr-auto max-w-[90%] rounded-xl border bg-background px-3 py-2 text-sm text-muted-foreground">
                                            <div className="inline-flex items-center gap-2">
                                                <Loader2 className="size-4 animate-spin" />
                                                <span>
                                                    {streamStatusLabel(
                                                        streamStatus,
                                                        mode,
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {streamStatusDetail(
                                                    streamStatus,
                                                    mode,
                                                )}
                                            </p>
                                            {mode === 'deep' &&
                                                planningPreview.trim() !== '' && (
                                                    <div className="mt-2 rounded-md border bg-muted/30 p-2">
                                                        <p className="text-[11px] font-medium text-muted-foreground">
                                                            Live Planning
                                                        </p>
                                                        <p className="mt-1 whitespace-pre-wrap text-[12px] text-muted-foreground">
                                                            {planningPreview}
                                                        </p>
                                                    </div>
                                                )}
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                Elapsed: {elapsedSeconds}s
                                                {' · '}
                                                Output: {streamedChars} chars
                                            </p>
                                            {statusTrail.length > 0 && (
                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                    Steps:{' '}
                                                    {statusTrail.join(' -> ')}
                                                </p>
                                            )}
                                            {toolActivity.length > 0 && (
                                                <div className="mt-2 rounded-md border bg-muted/30 p-2">
                                                    <p className="text-[11px] font-medium text-muted-foreground">
                                                        Live Tool Activity
                                                    </p>
                                                    <div className="mt-1 space-y-1">
                                                        {toolActivity.map(
                                                            (item, index) => (
                                                                <p
                                                                    key={`${item}-${index}`}
                                                                    className="text-[11px] text-muted-foreground"
                                                                >
                                                                    {item}
                                                                </p>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="mt-auto pt-6">
                                <Card className="gap-3 px-3 py-3 sm:px-4">
                                    <div className="flex items-center gap-2 text-xs">
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => void startNewChat()}
                                            disabled={
                                                isSending ||
                                                isCreatingConversation ||
                                                switchingConversationId !== null
                                            }
                                        >
                                            {isCreatingConversation && (
                                                <Loader2 className="size-4 animate-spin" />
                                            )}
                                            New chat
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={mode === 'auto' ? 'default' : 'secondary'}
                                            onClick={() => setMode('auto')}
                                        >
                                            Fast
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={mode === 'deep' ? 'default' : 'secondary'}
                                            onClick={() => setMode('deep')}
                                        >
                                            Deep
                                        </Button>
                                        <span className="text-muted-foreground">
                                            {mode === 'deep'
                                                ? 'glm-5 plans, coder executes'
                                                : 'single-pass by intent'}
                                        </span>
                                    </div>
                                    <form
                                        className="relative"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            void submitPrompt();
                                        }}
                                    >
                                        <textarea
                                            value={prompt}
                                            onChange={(event) =>
                                                setPrompt(event.target.value)
                                            }
                                            disabled={
                                                isSending ||
                                                isCreatingConversation ||
                                                switchingConversationId !== null
                                            }
                                            onKeyDown={(event) => {
                                                if (
                                                    event.key === 'Enter' &&
                                                    !event.shiftKey
                                                ) {
                                                    event.preventDefault();
                                                    void submitPrompt();
                                                }
                                            }}
                                            maxLength={3000}
                                            rows={3}
                                            placeholder="Ask about your Laravel project..."
                                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring/50 min-h-24 w-full resize-none rounded-md border px-3 py-2 pr-12 text-sm shadow-xs outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                                        />
                                        <Button
                                            size="icon"
                                            type="submit"
                                            className="absolute right-2 bottom-2 size-9"
                                            disabled={
                                                isSending ||
                                                isCreatingConversation ||
                                                switchingConversationId !== null
                                            }
                                        >
                                            <Send className="size-4" />
                                        </Button>
                                    </form>
                                    <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                        <div className="flex items-center gap-3">
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1.5 hover:text-foreground"
                                            >
                                                <Paperclip className="size-3.5" />
                                                Attach
                                            </button>
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1.5 hover:text-foreground"
                                            >
                                                <Mic className="size-3.5" />
                                                Voice Message
                                            </button>
                                        </div>
                                        <span>{prompt.length}/3000</span>
                                    </div>
                                    {warnings.length > 0 && (
                                        <div className="rounded-md border border-amber-300/50 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                            {warnings.join(' ')}
                                        </div>
                                    )}
                                </Card>
                                <p className="mt-3 text-center text-xs text-muted-foreground">
                                    Routing: `glm-5:cloud` for planning,
                                    `qwen3-coder-next:cloud` for coding,
                                    `qwen3-embedding:0.6b` for retrieval. Deep
                                    mode runs planning then execution.
                                </p>
                            </div>
                        </div>
                    </section>

                    <aside className="w-full border-t bg-muted/30 lg:w-80 lg:border-t-0 lg:border-l">
                        <div className="flex items-center justify-between border-b px-4 py-4">
                            <h2 className="text-sm font-semibold">
                                Conversations
                            </h2>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => void startNewChat()}
                                disabled={
                                    isSending ||
                                    isCreatingConversation ||
                                    switchingConversationId !== null
                                }
                            >
                                New
                            </Button>
                        </div>
                        <div className="max-h-[360px] space-y-1 overflow-y-auto p-2 lg:h-[calc(100vh-8rem)] lg:max-h-none">
                            {conversations.length === 0 ? (
                                <div className="rounded-lg border bg-background px-3 py-3 text-xs text-muted-foreground">
                                    No conversations yet.
                                </div>
                            ) : (
                                conversations.map((item) => (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() =>
                                            void loadConversation(item.id)
                                        }
                                        disabled={
                                            isSending ||
                                            isCreatingConversation ||
                                            switchingConversationId !== null
                                        }
                                        className={`w-full rounded-lg border px-3 py-3 text-left transition-colors ${
                                            item.id === conversationId
                                                ? 'border-primary bg-accent'
                                                : 'bg-background hover:bg-accent'
                                        }`}
                                    >
                                        <p className="text-sm font-medium">
                                            {item.title &&
                                            item.title !== 'AI Assistant'
                                                ? item.title
                                                : `Conversation #${item.id}`}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {item.preview}
                                        </p>
                                    </button>
                                ))
                            )}
                        </div>
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
