<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportTicketMessageRequest;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Http\Requests\UpdateSupportTicketRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupportTicketController extends Controller
{
    /**
     * Display the support tickets page with list of tickets.
     */
    public function index(Request $request): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        
        $tickets = SupportTicket::with(['user'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'title' => $ticket->title,
                    'description' => $ticket->description,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
                    'user' => [
                        'id' => $ticket->user->id,
                        'name' => $ticket->user->name,
                        'email' => $ticket->user->email,
                    ],
                ];
            })
            ->toArray();

        return Inertia::render('support-tickets/index', [
            'tickets' => $tickets,
        ]);
    }

    /**
     * Show the form for creating a new ticket.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('support-tickets/create');
    }

    /**
     * Store a newly created ticket.
     */
    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'priority' => $request->input('priority', 'medium'),
            'status' => 'open',
        ]);

        return response()->json([
            'ok' => true,
            'ticket' => [
                'id' => $ticket->id,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    /**
     * Display the specified ticket.
     */
    public function show(Request $request, SupportTicket $ticket): Response|JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user || $ticket->user_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Ticket not found or unauthorized',
            ], 404);
        }

        $messages = SupportTicketMessage::where('ticket_id', $ticket->id)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(function ($message) use ($user) {
                return [
                    'id' => $message->id,
                    'ticket_id' => $message->ticket_id,
                    'user_id' => $message->user_id,
                    'message' => $message->message,
                    'is_internal' => $message->is_internal,
                    'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                    'user' => [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'email' => $message->user->email,
                    ],
                    'isOwnMessage' => $message->user_id === $user->id,
                ];
            })
            ->toArray();

        $ticketData = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'created_at' => $ticket->created_at->format('Y-m-d H:i:s'),
            'resolved_at' => $ticket->resolved_at?->format('Y-m-d H:i:s'),
            'closed_at' => $ticket->closed_at?->format('Y-m-d H:i:s'),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'ticket' => $ticketData,
                'messages' => $messages,
            ]);
        }

        return Inertia::render('support-tickets/show', [
            'ticket' => $ticketData,
            'messages' => $messages,
        ]);
    }

    /**
     * Show the form for editing the specified ticket.
     */
    public function edit(Request $request, SupportTicket $ticket): Response|JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user || $ticket->user_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Ticket not found or unauthorized',
            ], 404);
        }

        $ticketData = [
            'id' => $ticket->id,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'ticket' => $ticketData,
            ]);
        }

        return Inertia::render('support-tickets/edit', [
            'ticket' => $ticketData,
        ]);
    }

    /**
     * Update the specified ticket.
     */
    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user || $ticket->user_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Ticket not found or unauthorized',
            ], 404);
        }

        $ticket->update($request->only([
            'title',
            'description',
            'status',
            'priority',
        ]));

        // Handle status changes
        if ($request->input('status') === 'resolved' && !$ticket->resolved_at) {
            $ticket->update(['resolved_at' => now()]);
        }

        if ($request->input('status') === 'closed' && !$ticket->closed_at) {
            $ticket->update(['closed_at' => now()]);
        }

        return response()->json([
            'ok' => true,
            'ticket' => [
                'id' => $ticket->id,
                'title' => $ticket->title,
                'description' => $ticket->description,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'resolved_at' => $ticket->resolved_at?->format('Y-m-d H:i:s'),
                'closed_at' => $ticket->closed_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Remove the specified ticket.
     */
    public function destroy(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if (!$user || $ticket->user_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Ticket not found or unauthorized',
            ], 404);
        }

        $ticket->delete();

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * Store a message for a ticket.
     */
    public function storeMessage(StoreSupportTicketMessageRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $ticket = SupportTicket::find($request->input('ticket_id'));

        if (!$ticket || $ticket->user_id !== $user->id) {
            return response()->json([
                'ok' => false,
                'error' => 'Ticket not found or unauthorized',
            ], 404);
        }

        $message = SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $request->input('message'),
            'is_internal' => $request->boolean('is_internal'),
        ]);

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'ticket_id' => $message->ticket_id,
                'user_id' => $message->user_id,
                'message' => $message->message,
                'is_internal' => $message->is_internal,
                'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'isOwnMessage' => true,
            ],
        ]);
    }
}
