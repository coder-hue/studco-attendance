import Foundation

struct AdminUser: Codable { let id: Int; let name: String; let role: String }
struct SessionResponse: Codable { let ok: Bool; let user: AdminUser }
struct APIErrorResponse: Codable { let error: String }

struct AttendanceEvent: Codable, Identifiable, Hashable {
    let id: Int
    let title: String
    let date: String
    let startTime: String
    let endTime: String
    let isOpen: Bool
    let earlyIsOpen: Bool
    let attendanceMode: String
    let checkinMode: String?
    let isArchived: Bool
    let isFinalized: Bool
    let memberCount: Int
    let presentCount: Int
    let checkinURL: String?
    let earlyCheckinURL: String?

    enum CodingKeys: String, CodingKey {
        case id, title, date
        case startTime = "start_time"
        case endTime = "end_time"
        case isOpen = "is_open"
        case earlyIsOpen = "early_is_open"
        case attendanceMode = "attendance_mode"
        case checkinMode = "checkin_mode"
        case isArchived = "is_archived"
        case isFinalized = "is_finalized"
        case memberCount = "member_count"
        case presentCount = "present_count"
        case checkinURL = "checkin_url"
        case earlyCheckinURL = "early_checkin_url"
    }
}

struct EventsResponse: Codable { let events: [AttendanceEvent] }

struct RosterMember: Codable, Identifiable {
    let id: Int
    let name: String
    let status: String
    let checkInTime: Date?
    let points: Int?
    enum CodingKeys: String, CodingKey { case id, name, status, points; case checkInTime = "check_in_time" }
}

struct EventResponse: Codable { let event: AttendanceEvent; let roster: [RosterMember] }
struct CreateEventResponse: Codable { let ok: Bool; let created: Bool; let eventID: Int; enum CodingKeys: String, CodingKey { case ok, created; case eventID = "event_id" } }

enum AdminAPIError: LocalizedError {
    case server(String)
    case invalidResponse
    var errorDescription: String? {
        switch self { case .server(let message): message; case .invalidResponse: "The server returned an invalid response." }
    }
}
