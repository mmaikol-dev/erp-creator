<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreHospitalRequest;
use App\Http\Requests\UpdateHospitalRequest;
use App\Models\Hospital;
use Inertia\Inertia;
use Inertia\Response;

class HospitalController extends Controller
{
    /**
     * Display a listing of the hospitals.
     */
    public function index(): Response
    {
        $hospitals = Hospital::orderBy('name')->get();

        return Inertia::render('hospitals/index', [
            'hospitals' => $hospitals,
        ]);
    }

    /**
     * Show the form for creating a new hospital.
     */
    public function create(): Response
    {
        return Inertia::render('hospitals/create');
    }

    /**
     * Store a newly created hospital in storage.
     */
    public function store(StoreHospitalRequest $request): Response
    {
        Hospital::create($request->validated());

        return redirect()->route('hospitals.index');
    }

    /**
     * Display the specified hospital.
     */
    public function show(Hospital $hospital): Response
    {
        return Inertia::render('hospitals/show', [
            'hospital' => $hospital,
        ]);
    }

    /**
     * Show the form for editing the specified hospital.
     */
    public function edit(Hospital $hospital): Response
    {
        return Inertia::render('hospitals/edit', [
            'hospital' => $hospital,
        ]);
    }

    /**
     * Update the specified hospital in storage.
     */
    public function update(UpdateHospitalRequest $request, Hospital $hospital): Response
    {
        $hospital->update($request->validated());

        return redirect()->route('hospitals.index');
    }

    /**
     * Remove the specified hospital from storage.
     */
    public function destroy(Hospital $hospital)
    {
        $hospital->delete();

        return redirect()->route('hospitals.index');
    }
}