import Foundation

struct AdminAPI {
    static let shared = AdminAPI()
    private let endpoint = URL(string: "https://attendance.coderhue.dev/mobile-admin-api.php")!
    private var decoder: JSONDecoder { let value=JSONDecoder(); value.dateDecodingStrategy = .iso8601; return value }

    func session() async throws -> AdminUser { try await get("session", as: SessionResponse.self).user }
    func events() async throws -> [AttendanceEvent] { try await get("events", as: EventsResponse.self).events }
    func event(_ id: Int) async throws -> EventResponse { try await get("event", query: [URLQueryItem(name:"event_id",value:String(id))], as: EventResponse.self) }
    func login(username: String, password: String) async throws -> AdminUser { try await post("login", body:["username":username,"password":password], as:SessionResponse.self).user }
    func logout() async throws { _ = try await post("logout", body:[:], as:BasicResponse.self) }
    func createEvent(title: String, date: String, open: String, close: String, mode: String) async throws -> Int { try await post("create_event",body:["title":title,"event_date":date,"open_time":open,"close_time":close,"attendance_mode":mode],as:CreateEventResponse.self).eventID }
    func setEventState(_ id: Int, state: String, lane: String = "full") async throws -> EventResponse { try await post("toggle_event",body:["event_id":id,"state":state,"lane":lane],as:EventResponse.self) }
    func updateEvent(_ id: Int, title: String, date: String, open: String, close: String) async throws -> EventResponse { try await post("update_event",body:["event_id":id,"title":title,"event_date":date,"open_time":open,"close_time":close],as:EventResponse.self) }
    func deleteEvent(_ id: Int) async throws { _ = try await post("delete_event",body:["event_id":id],as:BasicResponse.self) }
    func finalizeEvent(_ id: Int) async throws -> EventResponse { try await post("finalize_event",body:["event_id":id],as:EventResponse.self) }
    func setAttendance(eventID: Int, memberID: Int, points: Int?) async throws -> EventResponse { try await post("set_attendance",body:["event_id":eventID,"member_id":memberID,"status":points.map(String.init) ?? "missing"],as:EventResponse.self) }

    private struct BasicResponse: Codable { let ok: Bool }
    private func get<T: Decodable>(_ action: String, query: [URLQueryItem] = [], as type: T.Type) async throws -> T {
        var components=URLComponents(url:endpoint,resolvingAgainstBaseURL:false)!; components.queryItems=[URLQueryItem(name:"action",value:action)]+query
        let (data,response)=try await URLSession.shared.data(from:components.url!); return try decode(data,response,as:type)
    }
    private func post<T: Decodable>(_ action: String, body: [String:Any], as type: T.Type) async throws -> T {
        var components=URLComponents(url:endpoint,resolvingAgainstBaseURL:false)!; components.queryItems=[URLQueryItem(name:"action",value:action)]
        var request=URLRequest(url:components.url!); request.httpMethod="POST"; request.setValue("application/json",forHTTPHeaderField:"Content-Type"); request.httpBody=try JSONSerialization.data(withJSONObject:body)
        let (data,response)=try await URLSession.shared.data(for:request); return try decode(data,response,as:type)
    }
    private func decode<T: Decodable>(_ data: Data,_ response: URLResponse,as type:T.Type) throws -> T {
        guard let http=response as? HTTPURLResponse else { throw AdminAPIError.invalidResponse }
        guard 200..<300 ~= http.statusCode else { throw AdminAPIError.server((try? decoder.decode(APIErrorResponse.self,from:data).error) ?? "Request failed (\(http.statusCode)).") }
        do { return try decoder.decode(type,from:data) } catch { throw AdminAPIError.invalidResponse }
    }
}
