<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use Illuminate\Support\Facades\Auth;
use App\Models\Gender;
use App\Models\Company;
use App\Models\JobPost;
use App\Models\JobType;
use App\Models\JobLocation;
use Illuminate\Http\Request;
use App\Models\EmployeePosition;
use App\Models\TypeOfEmployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Vanthao03596\HCVN\Models\Province;
use App\Http\Requests\StoreJobPostRequest;

class JobController extends Controller
{
    public function getAll()
    {
        $jobPosts = JobPost::where('is_active', 1)->latest()->take(20)->get();
        foreach ($jobPosts as $key => $jobPost) {
            $jobPost->name = $jobPost->company->name;
            $jobPost->location = $jobPost->company->province->name;
            $jobPost->address = $jobPost->company->address;
            $jobPost->jobTypes = $jobPost->jobType->name;
            $jobPost->employeePositions = $jobPost->employeePosition->name;
            $jobPost->typeOfEmployments = $jobPost->typeOfEmployment->name;
        }
        return response()->json($jobPosts);
    }

    public function getJobInfo()
    {
        try {
            $jobTypes = JobType::all();
            $employeePositions = EmployeePosition::all();
            $typeOfEmployments = TypeOfEmployment::all();
            $genders = Gender::all();
            $provinces = Province::all();
            return response()->json([
                'jobTypes' => $jobTypes,
                'employeePositions' => $employeePositions,
                'typeOfEmployments' => $typeOfEmployments,
                'genders' => $genders,
                'provinces' => $provinces,
            ]);
        } catch (Exception $e) {
            return response()->json($e->getMessage(), 400);
        }
    }

    public function store(StoreJobPostRequest $request)
    {
        try {
            DB::beginTransaction();
            $jobPost = new JobPost();
            $jobPost->fill($request->all());
            $jobPost->company_id = Company::where('user_id', Auth::id())->first()->id;
            $jobPost->job_code = "CODE" . $jobPost->company_id;
            $jobPost->save();
            $jobPost->job_code = $jobPost->job_code . $jobPost->id;
            $jobPost->save();
            DB::commit();
            return response()->json($jobPost);
        } catch (Exception $e) {
            DB::rollback();
            return response()->json($e->getMessage(), 400);
        }
    }

    public function edit(StoreJobPostRequest $request, $id)
    {
        $jobPost = JobPost::find($id);
        $jobPost->update($request->all());
        $companyId = Company::where('user_id', Auth::id())->pluck('id')[0];
        $jobPosts = JobPost::where('company_id', $companyId)->latest()->get();
        return response()->json($jobPosts);
    }

    public function search(Request $request)
    {
        $search = $request->search;
        $provinceId = $request->province_id;
        $jobTypeId = $request->job_type_id;
        $jobPosts = JobPost::where(function ($query) use ($jobTypeId, $provinceId, $search) {
            $query->where('title', 'like', '%' . $search . '%')
                ->when($jobTypeId, function ($query, $jobTypeId) {
                    return $query->where('job_type_id', $jobTypeId);
                })
                ->when($provinceId, function ($query, $provinceId) {
                    $companies = Company::where('province_id', $provinceId)->get();
                    $companiesId = [];
                    foreach ($companies as $company) {
                        $companiesId[] = $company->id;
                    }
                    return $query->whereIn('company_id', $companiesId);
                });
        })
            ->orWhere(function ($query) use ($jobTypeId, $provinceId, $search) {
                $query->where('description', 'like', '%' . $search . '%')
                    ->when($jobTypeId, function ($query, $jobTypeId) {
                        return $query->where('job_type_id', $jobTypeId);
                    })
                    ->when($provinceId, function ($query, $provinceId) {
                        $companies = Company::where('province_id', $provinceId)->get();
                        $companiesId = [];
                        foreach ($companies as $company) {
                            $companiesId[] = $company->id;
                        }
                        return $query->whereIn('company_id', $companiesId);
                    });
            })
            ->get();
        if (count($jobPosts) > 0) {
            foreach ($jobPosts as $key => $jobPost) {
                $jobPost->name = $jobPost->company->name;
                $jobPost->address = $jobPost->company->address;
                $jobPost->jobTypes = $jobPost->jobType->name;
                $jobPost->employeePositions = $jobPost->employeePosition->name;
                $jobPost->typeOfEmployments = $jobPost->typeOfEmployment->name;
            }
        }
        return response()->json(compact('jobPosts', 'provinceId', 'jobTypeId', 'search'));
    }

    public function getDetail($id)
    {
        $jobPost = JobPost::find($id);
        $jobPost->address = $jobPost->company->address;
        $jobPost->jobTypes = $jobPost->jobType->name;
        $jobPost->employeePositions = $jobPost->employeePosition->name;
        $jobPost->typeOfEmployments = $jobPost->typeOfEmployment->name;
        $jobPost->genders = $jobPost->gender->name;
        $jobs = JobPost::where('job_type_id', $jobPost->job_type_id)->where('is_active', 1)->where('id', '!=', $jobPost->id)->inRandomOrder()->take(3)->get();
        foreach ($jobs as $job) {
            $job->name = $job->company->name;
            $job->location = $job->company->province->name;
            $job->address = $job->company->address;
            $job->jobTypes = $job->jobType->name;
            $job->employeePositions = $job->employeePosition->name;
            $job->typeOfEmployments = $job->typeOfEmployment->name;
            $job->companyImg = $job->company->image;
        }
        $company = Company::find($jobPost->company_id);
        $company->location = $company->province->name;
        $company->jobPostAmount = JobPost::where('company_id', $company->id)->where('is_active', 1)->count();
        return response()->json(['jobPost' => $jobPost, 'jobs' => $jobs, 'company' => $company]);
    }

    public function getJobByCompany($page, $size = 20)
    {
        $size = 100;
        $companyId = Company::where('user_id', Auth::id())->first()->id;
        $jobPosts = JobPost::where('company_id', $companyId)->latest()->skip(($page - 1) * $size)->take($size)->get();
        $totalPage = ceil(JobPost::where('company_id', $companyId)->count() / $size);
        return response()->json(compact('jobPosts', 'totalPage'));
    }

    public function delete($id)
    {
        JobPost::destroy($id);
        $companyId = Company::where('user_id', Auth::id())->pluck('id')[0];
        $jobPosts = JobPost::where('company_id', $companyId)->latest()->get();
        return response()->json($jobPosts);
    }

    public function changeStatus(Request $request)
    {
        $jobPost = JobPost::find($request->id);
        $jobPost->is_active = $jobPost->is_active === 0 ? 1 : 0;
        $jobPost->save();
        $companyId = Company::where('user_id', Auth::id())->pluck('id')[0];
        $jobPosts = JobPost::where('company_id', $companyId)->latest()->get();
        return response()->json($jobPosts);
    }
}
